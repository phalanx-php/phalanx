<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/ws-client

Async WebSocket client that connects to remote servers with fiber-based message consumption and automatic backpressure. The outbound counterpart to `phalanx/ws-server` -- both use `Channel` for iteration, so sending and receiving messages reads the same way regardless of which side initiated the connection.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Connecting](#connecting)
- [Sending Messages](#sending-messages)
- [Receiving Messages](#receiving-messages)
- [Configuration](#configuration)
- [Closing](#closing)
- [Backpressure](#backpressure)
- [Integration with phalanx/cdp](#integration-with-phalanxcdp)
- [Error Handling](#error-handling)

## Installation

```bash
composer require phalanx/ws-client
```

Requires PHP 8.4+, `phalanx/core`, `phalanx/stream`, `phalanx/ws-server` (shared `WsMessage` and `WsCloseCode` types), `ratchet/rfc6455`, and `react/socket`.

## Quick Start

```php
<?php

use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\Client\WsClientConnection;
use Phalanx\WebSocket\WsMessage;

final readonly class PriceSubscriber implements Scopeable
{
    public function __invoke(Scope $scope): mixed
    {
        $conn = WsClientConnection::connect($scope, 'wss://feed.exchange.com/prices');

        $conn->sendJson(['action' => 'subscribe', 'symbols' => ['BTC', 'ETH']]);

        foreach ($conn->inbound->consume() as $msg) {
            $price = $msg->decode();
            echo "{$price['symbol']}: {$price['price']}\n";
        }

        return null;
    }
}
```

The `foreach` loop suspends the fiber -- not the event loop -- until each frame arrives. When the server closes the connection, the iterator completes and execution continues.

## Connecting

`WsClientConnection::connect()` performs TCP connection, TLS negotiation (for `wss://`), and the RFC 6455 handshake in a single call. It accepts a `Suspendable` scope, a URL, and an optional config:

```php
<?php

use Phalanx\WebSocket\Client\WsClientConnection;
use Phalanx\WebSocket\Client\WsClientConfig;

// Minimal -- defaults for everything
$conn = WsClientConnection::connect($scope, 'ws://localhost:8080/ws/feed');

// TLS -- wss:// triggers automatic TLS negotiation
$conn = WsClientConnection::connect($scope, 'wss://api.example.com/stream');

// Custom config
$config = WsClientConfig::default()
    ->withConnectTimeout(10.0)
    ->withInboundBufferSize(256);

$conn = WsClientConnection::connect($scope, 'wss://api.example.com/stream', $config);
```

URL parsing handles `ws://` and `wss://` schemes, default ports (80 and 443), paths, and query strings. The scope's cancellation token is respected during both the TCP connect and the handshake -- if the scope cancels, the connection attempt aborts cleanly.

The `connect()` method requires `Suspendable` -- the narrowest scope interface that provides `await()`. This means any scope level from `Suspendable` up through `ExecutionScope` works.

## Sending Messages

Send frames through the connection object. All send methods are fire-and-forget -- they write to the transport buffer and return immediately:

```php
<?php

use Phalanx\WebSocket\WsMessage;

// Text frame
$conn->sendText('hello');

// Binary frame
$conn->sendBinary($protobuf);

// JSON -- encodes to a text frame
$conn->sendJson(['action' => 'ping', 'ts' => time()]);

// Ping (keepalive)
$conn->ping();

// Any WsMessage directly
$conn->send(WsMessage::text('raw'));
```

Sending to a closed connection is a no-op. Check `$conn->isConnected` if you need to verify the transport is still writable.

## Receiving Messages

Inbound frames arrive through a `Channel` -- the same primitive used by `phalanx/ws-server`. Consume with `foreach`:

```php
<?php

foreach ($conn->inbound->consume() as $msg) {
    if ($msg->isText) {
        $data = $msg->decode();
        // Handle JSON payload
    }

    if ($msg->isBinary) {
        processBinaryFrame($msg->payload);
    }
}
// Loop exits when the connection closes or an error occurs
```

The server-side pattern is identical -- `WsScope` provides `$scope->connection->inbound`, and the client provides `$conn->inbound`. Same `Channel`, same `WsMessage` type, same iteration model.

Control frames (ping, pong, close) are handled automatically by the codec. Ping frames from the server receive an immediate pong response. Close frames complete the inbound channel. Application code only sees data frames (text and binary).

## Configuration

`WsClientConfig` uses immutable builder methods. Every option has a sensible default:

```php
<?php

use Phalanx\WebSocket\Client\WsClientConfig;

$config = WsClientConfig::default()
    ->withConnectTimeout(10.0)       // TCP + handshake timeout (default: 5.0s)
    ->withMaxMessageSize(1048576)    // Max inbound message bytes (default: 64KB)
    ->withMaxFrameSize(1048576)      // Max inbound frame bytes (default: 64KB)
    ->withPingInterval(15.0)         // Automatic ping interval (default: 30.0s, 0 to disable)
    ->withInboundBufferSize(256)     // Channel buffer capacity (default: 128)
    ->withReconnect(                 // Reconnection policy
        maxAttempts: 5,
        baseDelay: 2.0,              // Exponential backoff base (default: 1.0s)
    );
```

| Option | Default | Purpose |
|--------|---------|---------|
| `connectTimeout` | `5.0` | Seconds for TCP connect + WebSocket handshake |
| `maxMessageSize` | `65536` | Maximum assembled message payload in bytes |
| `maxFrameSize` | `65536` | Maximum single frame payload in bytes |
| `pingInterval` | `30.0` | Seconds between automatic ping frames (0 disables) |
| `maxReconnectAttempts` | `null` | Max reconnection attempts (`null` disables reconnect) |
| `reconnectBaseDelay` | `1.0` | Base delay for exponential backoff on reconnect |
| `inboundBufferSize` | `128` | Channel buffer size for inbound messages |

All `with*` methods return a new instance -- the original config is unchanged.

## Closing

Initiate a clean close with an optional close code and reason:

```php
<?php

use Phalanx\WebSocket\WsCloseCode;

// Normal close
$conn->close();

// Close with specific code and reason
$conn->close(WsCloseCode::Normal, 'session ended');

// Application-specific close code
$conn->close(WsCloseCode::GoingAway, 'client shutting down');
```

`close()` sends a close frame, completes the inbound channel (ending any active `foreach` loop), and terminates the transport. The automatic ping timer is cancelled. Calling `close()` on an already-closed connection is a no-op.

When the server initiates the close, the codec handles the close frame automatically -- the inbound channel completes, and any active `consume()` iterator exits cleanly.

## Backpressure

The inbound `Channel` enforces backpressure between the network and your consumer. When the channel buffer fills (default: 128 items):

1. The codec stops processing inbound data from the transport
2. TCP flow control kicks in -- the kernel buffers fill, the server's writes slow down
3. When the consumer drains the channel below 50% capacity, processing resumes

This prevents unbounded memory growth when the server sends faster than the consumer processes. No messages are dropped -- the fast producer waits for the slow consumer.

Adjust the buffer size through config if your use case needs more headroom:

```php
<?php

$config = WsClientConfig::default()
    ->withInboundBufferSize(512); // More buffer for bursty traffic
```

## Integration with phalanx/cdp

This package provides the WebSocket transport layer for `phalanx/cdp` (Chrome DevTools Protocol). CDP communicates with Chrome over a WebSocket connection -- `WsClientConnection` handles the framing, backpressure, and lifecycle while `phalanx/cdp` layers the CDP protocol on top.

If you are building browser automation or DevTools integrations, use `phalanx/cdp` directly -- it manages the `WsClientConnection` internally.

## Error Handling

Transport errors and handshake failures surface as exceptions through the scope's `await()` mechanism:

```php
<?php

use Phalanx\WebSocket\Client\WsClientConnection;

try {
    $conn = WsClientConnection::connect($scope, 'wss://api.example.com/stream');
} catch (\RuntimeException $e) {
    // Handshake failed (non-101 response, Sec-WebSocket-Accept mismatch)
    // Connection refused, DNS resolution failed, TLS error
    // Connect timeout exceeded
    $logger->error("WebSocket connect failed: {$e->getMessage()}");
}
```

Once connected, transport errors during the session complete the inbound channel with an error. The `consume()` iterator throws the exception at the point of iteration:

```php
<?php

try {
    foreach ($conn->inbound->consume() as $msg) {
        // Process messages
    }
} catch (\Throwable $e) {
    // Transport error during the session
    $logger->error("Connection error: {$e->getMessage()}");
}
```

Handshake failures include the server's response status line in the exception message. If the server returns a non-101 response, the full status line is available for debugging.
