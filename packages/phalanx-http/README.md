<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/http

Async HTTP server built on ReactPHP with scope-driven request handling. Every route handler receives an `ExecutionScope` with full access to concurrent task execution, service injection, and cancellation -- write concurrent data-fetching code that reads like sequential PHP.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Defining Routes](#defining-routes)
- [Route Groups](#route-groups)
- [Route Parameters](#route-parameters)
- [Concurrent Request Handling](#concurrent-request-handling)
- [Middleware](#middleware)
- [Mounting Sub-Groups](#mounting-sub-groups)
- [Loading Routes from Files](#loading-routes-from-files)
- [Server-Sent Events](#server-sent-events)
- [UDP Listeners](#udp-listeners)
- [WebSocket Integration](#websocket-integration)

## Installation

```bash
composer require phalanx/http
```

Requires PHP 8.4+, `phalanx/core`, `react/http`, and `nikic/fast-route`.

## Quick Start

```php
<?php

use Phalanx\Http\Route;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;
use React\Http\Message\Response;

$app = Application::starting()->compile();

$routes = RouteGroup::of([
    'GET /hello' => new Route(
        fn: static fn($scope) => Response::plaintext('Hello, Phalanx!'),
    ),
]);

Runner::from($app)
    ->withRoutes($routes)
    ->run('0.0.0.0:8080');
```

```
$ curl http://localhost:8080/hello
Hello, Phalanx!
```

For anything beyond a one-liner, use an invokable class implementing `Scopeable` or `Executable` instead of an inline closure. Named handlers are traceable, testable, and carry their own identity through the system.

## Defining Routes

A `Route` wraps a handler and a `RouteConfig`. The handler receives a `RequestScope` (which extends `ExecutionScope`) at dispatch time. Handlers can be closures for trivial cases, or invokable classes for anything with real logic:

```php
<?php

declare(strict_types=1);

use Phalanx\Http\RequestScope;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final readonly class ShowUser implements Scopeable
{
    public function __invoke(Scope $scope): mixed
    {
        /** @var RequestScope $scope */
        $user = $scope->service(UserRepository::class)->find(42);

        return Response::json($user);
    }
}
```

Wire it into a route:

```php
<?php

use Phalanx\Http\Route;

$route = new Route(fn: new ShowUser());
```

Route keys use the `METHOD /path` format. Multiple methods can be comma-separated:

```php
<?php

$routes = RouteGroup::of([
    'GET /users'        => $listUsers,
    'POST /users'       => $createUser,
    'GET,HEAD /health'  => $healthCheck,
]);
```

## Route Groups

`RouteGroup` collects routes into a dispatch table backed by FastRoute. Build from an array or use the fluent API:

```php
<?php

use Phalanx\Http\RouteGroup;

// From an array
$routes = RouteGroup::of([
    'GET /users'     => $listUsers,
    'POST /users'    => $createUser,
    'GET /users/{id}' => $getUser,
]);

// Fluent builder
$routes = RouteGroup::create()
    ->route('/users', $listUsers, 'GET')
    ->route('/users', $createUser, 'POST')
    ->route('/users/{id}', $getUser, 'GET');

// Merge groups
$all = $apiRoutes->merge($adminRoutes);
```

## Route Parameters

Path parameters use `{name}` syntax with optional regex constraints:

```php
<?php

declare(strict_types=1);

use Phalanx\Http\RequestScope;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use React\Http\Message\Response;

final readonly class ShowUser implements Scopeable
{
    public function __invoke(Scope $scope): mixed
    {
        /** @var RequestScope $scope */
        $id = $scope->params->get('id');
        $user = $scope->service(UserRepository::class)->find($id);

        return Response::json($user);
    }
}
```

```php
<?php

use Phalanx\Http\Route;
use Phalanx\Http\RouteGroup;

$routes = RouteGroup::of([
    'GET /users/{id}' => new Route(fn: new ShowUser()),
    'GET /posts/{slug:[a-z0-9-]+}' => new Route(
        fn: static fn($scope) => Response::json(['slug' => $scope->params->get('slug')]),
    ),
]);
```

The `RequestScope` exposes `$request`, `$params`, `$query`, `$body`, and `$config` through typed property hooks. Convenience methods -- `$scope->method()`, `$scope->path()`, `$scope->header()`, `$scope->isJson()`, `$scope->bearerToken()` -- wrap common PSR-7 access patterns.

## Concurrent Request Handling

Every route handler has access to Phalanx's concurrency primitives through the scope. Fetch data from multiple sources concurrently within a single request:

```php
<?php

declare(strict_types=1);

use Phalanx\ExecutionScope;
use Phalanx\Http\RequestScope;
use Phalanx\Task;
use Phalanx\Task\Executable;
use React\Http\Message\Response;

final readonly class DashboardHandler implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        /** @var RequestScope $scope */
        [$stats, $alerts, $recent] = $scope->concurrent([
            Task::of(static fn($s) => $s->service(PgPool::class)->query(
                'SELECT count(*) as total FROM orders WHERE date = CURRENT_DATE'
            )),
            Task::of(static fn($s) => $s->service(RedisClient::class)->get('alerts:active')),
            Task::of(static fn($s) => $s->service(PgPool::class)->query(
                'SELECT * FROM activity ORDER BY created_at DESC LIMIT 10'
            )),
        ]);

        return Response::json(compact('stats', 'alerts', 'recent'));
    }
}
```

```php
<?php

$dashboard = new Route(fn: new DashboardHandler());
```

Three I/O operations, one request, wall-clock time of the slowest. The handler reads like synchronous code -- no promises, no callbacks, no `yield`.

## Middleware

Wrap an entire route group with middleware. Middleware receives the scope before the matched route handler:

```php
<?php

$api = RouteGroup::of([
    'GET /me'       => $getProfile,
    'PUT /me'       => $updateProfile,
    'GET /settings' => $getSettings,
])->wrap($authMiddleware, $corsMiddleware);
```

## Mounting Sub-Groups

Nest route groups under a path prefix with `mount()`:

```php
<?php

$v1 = RouteGroup::of([
    'GET /users'  => $listUsers,
    'POST /users' => $createUser,
]);

$v2 = RouteGroup::of([
    'GET /users'  => $listUsersV2,
    'POST /users' => $createUserV2,
]);

$api = RouteGroup::create()
    ->mount('/api/v1', $v1)
    ->mount('/api/v2', $v2);
```

Requests to `/api/v1/users` and `/api/v2/users` dispatch to their respective handlers.

## Loading Routes from Files

`RouteLoader` scans a directory of PHP files that each return a `RouteGroup`:

```php
<?php

use Phalanx\Http\RouteLoader;

$routes = RouteLoader::loadDirectory(__DIR__ . '/routes');
```

Each file defines its routes with invokable handlers:

```php
<?php

// routes/users.php
use Phalanx\Http\Route;
use Phalanx\Http\RouteGroup;

return RouteGroup::of([
    'GET /users'      => new Route(fn: new ListUsers()),
    'GET /users/{id}' => new Route(fn: new ShowUser()),
    'POST /users'     => new Route(fn: new CreateUser()),
]);
```

`Runner` accepts directory paths directly:

```php
<?php

Runner::from($app)
    ->withRoutes(__DIR__ . '/routes')
    ->run();
```

## Server-Sent Events

Push real-time updates to clients with `SseResponse` and `SseChannel`.

### Single-stream SSE

`SseResponse` converts an `Emitter` into a streaming HTTP response:

```php
<?php

declare(strict_types=1);

use Phalanx\ExecutionScope;
use Phalanx\Http\RequestScope;
use Phalanx\Http\Sse\SseResponse;
use Phalanx\Stream\Emitter;
use Phalanx\Task\Executable;

final readonly class MetricsStream implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        /** @var RequestScope $scope */
        $source = Emitter::produce(static function ($ch, $ctx) use ($scope) {
            while (!$ctx->isCancelled()) {
                $data = $scope->service(MetricsCollector::class)->snapshot();
                $ch->emit(json_encode($data));
                $scope->delay(1.0);
            }
        });

        return SseResponse::from($source, $scope, event: 'metrics');
    }
}
```

```php
<?php

$events = new Route(fn: new MetricsStream());
```

### Broadcast SSE with SseChannel

`SseChannel` manages multiple connected clients with automatic replay on reconnect:

```php
<?php

use Phalanx\Http\Sse\SseChannel;

// Register the channel as a service
$channel = new SseChannel(bufferSize: 200, defaultEvent: 'update');

// Connect clients (in a route handler)
$channel->connect($responseStream, lastEventId: $request->getHeaderLine('Last-Event-ID') ?: null);

// Publish from anywhere with access to the channel
$channel->send(json_encode($payload), event: 'price-change');
```

Missed events replay automatically when a client reconnects with `Last-Event-ID`.

## UDP Listeners

The runner supports UDP alongside HTTP on the same event loop:

```php
<?php

Runner::from($app)
    ->withRoutes($routes)
    ->withUdp(
        handler: static function (string $data, string $remote, $scope): void {
            $scope->service(MetricsIngester::class)->ingest($data, $remote);
        },
        port: 8081,
    )
    ->run();
```

HTTP on 8080, UDP on 8081, single process.

## WebSocket Integration

The HTTP runner handles WebSocket upgrades natively. See [phalanx/websocket](../phalanx-websocket/README.md) for the WebSocket API, then wire it in:

```php
<?php

use Phalanx\WebSocket\WsRouteGroup;

Runner::from($app)
    ->withRoutes($httpRoutes)
    ->withWebsockets($wsRouteGroup)
    ->run();
```

HTTP and WebSocket traffic share a single TCP listener. The runner detects upgrade requests and routes them to the appropriate `WsRouteGroup`.
