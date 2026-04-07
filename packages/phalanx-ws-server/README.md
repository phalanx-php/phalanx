<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/ws-server

> **Phalanx** is a first-principles rethinking of what PHP can be when modern language features and a decade of async community work are treated as the foundation, not an afterthought. [Read more](https://github.com/havy-tech/phalanx-core#phalanx-core---async-php) in the core library.

Production-grade WebSocket server support with RFC 6455 handshake, topic-based pub/sub, and leak-free connection tracking via `WeakMap`. Integrates directly with the Phalanx HTTP runner -- WebSocket and HTTP traffic share a single port.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Connections](#connections)
- [Messages](#messages)
- [Routes](#routes)
- [Gateway Pub/Sub](#gateway-pubsub)
- [Connection Lifecycle](#connection-lifecycle)
- [Route Parameters](#route-parameters)
- [Integration with phalanx/http](#integration-with-phalanxhttp)

## Installation

```bash
composer require phalanx/ws-server
```

Requires PHP 8.4+, `phalanx/core`, `phalanx/stream`, `ratchet/rfc6455`, and `react/stream`.

## Quick Start

```php
<?php

use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsMessage;
use Phalanx\WebSocket\WsScope;

final class EchoHandler implements Scopeable
{
    public function __invoke(Scope $scope): mixed
    {
        assert($scope instanceof WsScope);
        $conn = $scope->connection;

        foreach ($conn->inbound->consume() as $msg) {
            $conn->send(WsMessage::text("echo: {$msg->payload}"));
        }

        return null;
    }
}
```

```php
<?php

use Phalanx\Application;
use Phalanx\Http\Runner;
use Phalanx\WebSocket\WsRouteGroup;

$ws = WsRouteGroup::of([
    '/ws/echo' => EchoHandler::class,
]);

$app = Application::starting()->compile();

Runner::from($app)
    ->withWebsockets($ws)
    ->run('0.0.0.0:8080');
```

Connect with any WebSocket client to `ws://localhost:8080/ws/echo` and every message comes back prefixed with `echo:`.

## Connections

`WsConnection` represents a single WebSocket peer. Each connection holds two channels -- `inbound` for received frames, `outbound` for frames to send:

Send frames with `$conn->send(WsMessage::text('hello'))`, `$conn->sendBinary($bytes)`, `$conn->ping()`, or `$conn->close()`. Check state with `$conn->id` and `$conn->isOpen`.

Inbound messages arrive through a channel that supports iteration:

```php
<?php

foreach ($conn->inbound->consume() as $msg) {
    // Process each frame as it arrives
}
// Loop exits when the connection closes
```

## Messages

`WsMessage` wraps a payload and opcode with named constructors for every frame type:

```php
<?php

use Phalanx\WebSocket\WsMessage;

$text   = WsMessage::text('{"action": "join"}');
$json   = WsMessage::json(['action' => 'join']); // Encode to JSON text frame
$binary = WsMessage::binary($protobuf);
$ping   = WsMessage::ping();
$pong   = WsMessage::pong();
$close  = WsMessage::close(WsCloseCode::Normal, 'goodbye');
```

Type checks use property hooks:

```php
<?php

if ($msg->isText) {
    $data = $msg->decode(); // Decode JSON payload, throws on invalid JSON
}

if ($msg->isBinary) {
    processBuffer($msg->payload);
}

if ($msg->isClose) {
    echo "Closed with code: {$msg->closeCode->value}\n";
}
```

## Routes

`WsRouteGroup` maps paths to handler class-strings. The `HandlerResolver` constructs the handler at upgrade time with constructor dependencies injected from the service container, then calls `__invoke` with a `WsScope`. The scope carries the connection, the upgrade request, route parameters, and the full `ExecutionScope`:

```php
<?php

use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsScope;

final class ChatHandler implements Scopeable
{
    public function __construct(
        private readonly MessageRepository $messages,
    ) {}

    public function __invoke(Scope $scope): mixed
    {
        assert($scope instanceof WsScope);
        $conn = $scope->connection;
        $room = $scope->params->get('room');

        foreach ($conn->inbound->consume() as $msg) {
            if ($msg->isText) {
                $this->messages->save($room, $msg->payload);
                $conn->send(WsMessage::text("received: {$msg->payload}"));
            }
        }

        return null;
    }
}
```

```php
<?php

use Phalanx\WebSocket\WsRouteGroup;

$ws = WsRouteGroup::of([
    '/ws/chat/{room}' => ChatHandler::class,
]);
```

When a route needs custom WebSocket settings, pair the class-string with a `WsConfig`:

```php
<?php

use Phalanx\WebSocket\WsConfig;
use Phalanx\WebSocket\WsRouteGroup;

$ws = WsRouteGroup::of([
    '/ws/chat/{room}'    => ChatHandler::class,
    '/ws/notifications'  => [NotificationStream::class, new WsConfig(pingInterval: 10.0)],
]);
```

Route keys accept bare paths (`/ws/chat`) or the explicit `WS /ws/chat` prefix.

## Gateway Pub/Sub

`WsGateway` manages connections and topics. It uses `WeakMap` internally -- when a connection object is garbage collected, its subscriptions vanish automatically. No manual cleanup, no memory leaks.

```php
<?php

use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsMessage;

$gateway = $scope->service(WsGateway::class);

// Register a connection
$gateway->register($conn);

// Subscribe to topics
$gateway->subscribe($conn, 'chat.room.42', 'notifications');

// Publish to all subscribers of a topic
$gateway->publish('chat.room.42', WsMessage::text($json));

// Publish excluding the sender
$gateway->publish('chat.room.42', WsMessage::text($json), exclude: $conn);

// Broadcast to every connected client
$gateway->broadcast(WsMessage::json(['type' => 'system', 'text' => 'Maintenance in 5 minutes']));

// Unsubscribe or remove entirely
$gateway->unsubscribe($conn, 'chat.room.42');
$gateway->unregister($conn);
```

## Connection Lifecycle

A typical chat handler that registers with the gateway, subscribes to a room, and relays messages:

```php
<?php

use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsMessage;
use Phalanx\WebSocket\WsScope;

final class ChatRoomHandler implements Scopeable
{
    public function __construct(
        private readonly WsGateway $gateway,
    ) {}

    public function __invoke(Scope $scope): mixed
    {
        assert($scope instanceof WsScope);
        $conn = $scope->connection;
        $room = $scope->params->get('room');
        $gateway = $this->gateway;

        $gateway->register($conn);
        $gateway->subscribe($conn, "chat.{$room}");

        $gateway->publish(
            "chat.{$room}",
            WsMessage::json(['type' => 'join', 'id' => $conn->id]),
            exclude: $conn,
        );

        foreach ($conn->inbound->consume() as $msg) {
            if ($msg->isText) {
                $gateway->publish("chat.{$room}", $msg, exclude: $conn);
            }

            if ($msg->isClose) {
                break;
            }
        }

        $gateway->publish(
            "chat.{$room}",
            WsMessage::json(['type' => 'leave', 'id' => $conn->id]),
        );

        $gateway->unregister($conn);

        return null;
    }
}
```

```php
<?php

use Phalanx\WebSocket\WsRouteGroup;

$ws = WsRouteGroup::of([
    '/ws/rooms/{room}' => ChatRoomHandler::class,
]);
```

The `foreach` loop over `$conn->inbound->consume()` blocks the fiber (not the event loop) until the next frame arrives. When the client disconnects, the iterator completes and execution continues with cleanup.

## Route Parameters

WebSocket routes support the same `{param}` syntax as HTTP routes:

```php
<?php

use Phalanx\WebSocket\WsRouteGroup;

$ws = WsRouteGroup::of([
    '/ws/rooms/{room}'         => RoomHandler::class,
    '/ws/users/{id:\\d+}/feed' => UserFeedHandler::class,
]);
```

Access parameters through `$scope->params`:

```php
<?php

$room = $scope->params->get('room');
$userId = $scope->params->get('id');
```

## Integration with phalanx/http

The HTTP `Runner` handles WebSocket upgrades on the same port as HTTP traffic. No separate server needed:

```php
<?php

use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;
use Phalanx\WebSocket\WsRouteGroup;

$http = RouteGroup::of([
    'GET /api/rooms'  => ListRooms::class,
    'POST /api/rooms' => CreateRoom::class,
]);

$ws = WsRouteGroup::of([
    '/ws/rooms/{room}' => ChatRoomHandler::class,
]);

Runner::from($app)
    ->withRoutes($http)
    ->withWebsockets($ws)
    ->run('0.0.0.0:8080');
```

HTTP requests go to the route group. Upgrade requests (with `Connection: Upgrade` and `Upgrade: websocket` headers) are routed to the `WsRouteGroup`. The `WsHandshake` class handles RFC 6455 negotiation and subprotocol selection automatically.
