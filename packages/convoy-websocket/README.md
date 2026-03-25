# convoy/websocket

Production-grade WebSocket support with RFC 6455 handshake, topic-based pub/sub, and leak-free connection tracking via `WeakMap`. Integrates directly with the Convoy HTTP runner -- WebSocket and HTTP traffic share a single port.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Connections](#connections)
- [Messages](#messages)
- [Routes](#routes)
- [Gateway Pub/Sub](#gateway-pubsub)
- [Connection Lifecycle](#connection-lifecycle)
- [Route Parameters](#route-parameters)
- [Integration with convoy/http](#integration-with-convoyhttp)

## Installation

```bash
composer require convoy/websocket
```

Requires PHP 8.4+, `convoy/core`, `convoy/stream`, `ratchet/rfc6455`, and `react/stream`.

## Quick Start

```php
use Convoy\Http\Runner;
use Convoy\WebSocket\WsGateway;
use Convoy\WebSocket\WsMessage;
use Convoy\WebSocket\WsRoute;
use Convoy\WebSocket\WsRouteGroup;

$ws = WsRouteGroup::of([
    '/ws/echo' => new WsRoute(
        fn: static function ($scope): void {
            $conn = $scope->connection;

            foreach ($conn->inbound->consume() as $msg) {
                $conn->send(WsMessage::text("echo: {$msg->payload}"));
            }
        },
    ),
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
foreach ($conn->inbound->consume() as $msg) {
    // Process each frame as it arrives
}
// Loop exits when the connection closes
```

## Messages

`WsMessage` wraps a payload and opcode with named constructors for every frame type:

```php
use Convoy\WebSocket\WsMessage;

$text   = WsMessage::text('{"action": "join"}');
$binary = WsMessage::binary($protobuf);
$ping   = WsMessage::ping();
$pong   = WsMessage::pong();
$close  = WsMessage::close(WsCloseCode::Normal, 'goodbye');
```

Type checks use property hooks:

```php
if ($msg->isText) {
    $data = $msg->json(); // Decode JSON payload, throws on invalid JSON
}

if ($msg->isBinary) {
    processBuffer($msg->payload);
}

if ($msg->isClose) {
    echo "Closed with code: {$msg->closeCode->value}\n";
}
```

## Routes

`WsRoute` defines a handler for a specific WebSocket path. The closure receives a `WsScope` with the connection, the upgrade request, route parameters, and the full `ExecutionScope`:

```php
use Convoy\WebSocket\WsRoute;

$chatRoute = new WsRoute(
    fn: static function ($scope): void {
        $conn = $scope->connection;
        $request = $scope->request;    // Original HTTP upgrade request
        $params = $scope->params;       // Route parameters

        foreach ($conn->inbound->consume() as $msg) {
            // Handle messages
        }
    },
);
```

Group routes into a `WsRouteGroup`:

```php
use Convoy\WebSocket\WsRouteGroup;

$ws = WsRouteGroup::of([
    '/ws/chat'        => $chatRoute,
    '/ws/feed'        => $feedRoute,
    '/ws/admin'       => $adminRoute,
]);
```

Route keys accept bare paths (`/ws/chat`) or the explicit `WS /ws/chat` prefix.

## Gateway Pub/Sub

`WsGateway` manages connections and topics. It uses `WeakMap` internally -- when a connection object is garbage collected, its subscriptions vanish automatically. No manual cleanup, no memory leaks.

```php
use Convoy\WebSocket\WsGateway;
use Convoy\WebSocket\WsMessage;

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
$gateway->broadcast(WsMessage::text(json_encode(['type' => 'system', 'text' => 'Maintenance in 5 minutes'])));

// Unsubscribe or remove entirely
$gateway->unsubscribe($conn, 'chat.room.42');
$gateway->unregister($conn);
```

## Connection Lifecycle

A typical chat handler that registers with the gateway, subscribes to a room, and relays messages:

```php
use Convoy\WebSocket\WsGateway;
use Convoy\WebSocket\WsMessage;
use Convoy\WebSocket\WsRoute;

$chat = new WsRoute(
    fn: static function ($scope): void {
        $conn = $scope->connection;
        $gateway = $scope->service(WsGateway::class);
        $room = $scope->params->get('room');

        $gateway->register($conn);
        $gateway->subscribe($conn, "chat.{$room}");

        // Announce arrival
        $gateway->publish(
            "chat.{$room}",
            WsMessage::text(json_encode(['type' => 'join', 'id' => $conn->id])),
            exclude: $conn,
        );

        foreach ($conn->inbound->consume() as $msg) {
            if ($msg->isText) {
                // Relay to everyone else in the room
                $gateway->publish("chat.{$room}", $msg, exclude: $conn);
            }

            if ($msg->isClose) {
                break;
            }
        }

        // Announce departure
        $gateway->publish(
            "chat.{$room}",
            WsMessage::text(json_encode(['type' => 'leave', 'id' => $conn->id])),
        );

        $gateway->unregister($conn);
    },
);
```

The `foreach` loop over `$conn->inbound->consume()` blocks the fiber (not the event loop) until the next frame arrives. When the client disconnects, the iterator completes and execution continues with cleanup.

## Route Parameters

WebSocket routes support the same `{param}` syntax as HTTP routes:

```php
$ws = WsRouteGroup::of([
    '/ws/rooms/{room}'          => $roomRoute,
    '/ws/users/{id:\\d+}/feed'  => $userFeedRoute,
]);
```

Access parameters through `$scope->params`:

```php
$room = $scope->params->get('room');
$userId = $scope->params->get('id');
```

## Integration with convoy/http

The HTTP `Runner` handles WebSocket upgrades on the same port as HTTP traffic. No separate server needed:

```php
use Convoy\Http\RouteGroup;
use Convoy\Http\Runner;
use Convoy\WebSocket\WsRouteGroup;

$http = RouteGroup::of([
    'GET /api/rooms'     => $listRooms,
    'POST /api/rooms'    => $createRoom,
]);

$ws = WsRouteGroup::of([
    '/ws/rooms/{room}' => $roomRoute,
]);

Runner::from($app)
    ->withRoutes($http)
    ->withWebsockets($ws)
    ->run('0.0.0.0:8080');
```

HTTP requests go to the route group. Upgrade requests (with `Connection: Upgrade` and `Upgrade: websocket` headers) are routed to the `WsRouteGroup`. The `WsHandshake` class handles RFC 6455 negotiation and subprotocol selection automatically.
