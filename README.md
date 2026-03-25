# Convoy

**Async coordination for PHP 8.4+ that reads like synchronous code.**

Convoy separates what you want from how it runs. You declare operations as plain PHP classes. Convoy handles fibers, event loops, worker processes, cancellation, and cleanup. No promise chains. No callback hell. No manual fiber management.

```php
$app = Application::starting()
    ->providers(new AppBundle())
    ->compile();

$scope = $app->createScope();

// Three services queried concurrently — one line
[$user, $orders, $prefs] = $scope->concurrent([
    new FetchUser($id),
    new FetchOrders($id),
    new FetchPreferences($id),
]);

// Retry with exponential backoff, 5s deadline
$result = $scope->retry(
    new ChargePayment($user, $orders),
    RetryPolicy::exponential(attempts: 3),
);

// Everything disposes automatically when scope ends
$scope->dispose();
```

## Packages

Install what you need:

```bash
composer require convoy/core           # Scopes, tasks, concurrency, services, cancellation
composer require convoy/console        # CLI framework with command routing
composer require convoy/http           # HTTP server on ReactPHP with routing and SSE
composer require convoy/parallel       # Worker process pools with IPC and supervisors
composer require convoy/stream         # Reactive streams with channels and backpressure
composer require convoy/postgres       # Async PostgreSQL via Amphp with LISTEN/NOTIFY
composer require convoy/redis          # Async Redis via clue/redis-react with pub/sub
composer require convoy/websocket      # WebSocket connections, gateway, pub/sub topics
composer require convoy/integrations   # AI (Claude, GPT) and Twilio (SMS, Voice) clients
```

`convoy/core` is the foundation. Every other package builds on it.

## What makes Convoy different

**Scoped execution, not global state.** Every operation runs inside a scope that carries services, cancellation tokens, and a disposal stack. When the scope ends, everything cleans up. No reliance on `__destruct` or manual GC management.

**Tasks are classes, not closures.** A task like `FetchUser` has identity -- it shows up in stack traces, can be serialized, retried, and sent to worker processes. Closures are anonymous. Convoy tasks are named computations.

**Concurrency without ceremony.** Call `$scope->concurrent([...])` and get back an array of results. Call `$scope->race([...])` to get the first result. Call `$scope->settle([...])` to get all outcomes including failures. The scope manages fibers internally.

**Built on proven async.** ReactPHP event loop, React promises, Amphp for Postgres. Convoy does not reinvent async primitives. It provides the coordination layer above them.

## Requirements

- PHP 8.4+
- `ext-pcntl` for worker process pools (`convoy/parallel`)
- `ext-pgsql` for PostgreSQL (`convoy/postgres`)

## Quick start

A CLI tool that queries Docker concurrently:

```php
#!/usr/bin/env php
<?php

use Convoy\Application;
use Convoy\Console\CommandGroup;
use Convoy\Console\ConsoleRunner;

$app = Application::starting()
    ->providers(new DockerBundle())
    ->compile();

$commands = CommandGroup::of([
    'ps'     => new PsCommand(),
    'images' => new ImagesCommand(),
    'pull'   => new PullCommand(),
]);

$runner = ConsoleRunner::withCommands($app, $commands);
exit($runner->run($argv));
```

An HTTP server with WebSocket support:

```php
#!/usr/bin/env php
<?php

use Convoy\Application;
use Convoy\Http\Route;
use Convoy\Http\RouteGroup;
use Convoy\Http\Runner;
use Convoy\WebSocket\WsGateway;
use Convoy\WebSocket\WsRouteGroup;

$gateway = new WsGateway();

$app = Application::starting()
    ->providers(new AppBundle($gateway))
    ->compile();

$routes = RouteGroup::of([
    'GET /'      => Route::of(fn: new HomePage()),
    'POST /dump' => Route::of(fn: new DumpReceiver()),
]);

$ws = WsRouteGroup::of([
    '/live'    => LiveStream::route(),
    '/metrics' => MetricsStream::route(),
], gateway: $gateway);

Runner::from($app)
    ->withRoutes($routes)
    ->withWebsockets($ws)
    ->run('0.0.0.0:8080');
```

## Development

```bash
composer install          # Install all dependencies
composer test             # PHPUnit across all packages
composer analyse          # PHPStan level 8
composer check            # analyse + rector:dry + test (full CI gate)
```

## License

MIT
