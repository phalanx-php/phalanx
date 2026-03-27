<p align="center">
  <strong>V1 — Spartan Shield + Formation Spears</strong><br>
  <img src="logo-v1.svg" alt="Variant 1: Spartan" width="520"><br><br>
  <strong>V2 — Owl of Athena + Concurrent Feathers</strong><br>
  <img src="logo-v2.svg" alt="Variant 2: Athenian" width="520"><br><br>
  <strong>V3 — Vergina Sun + Radiating Dispatch</strong><br>
  <img src="logo-v3.svg" alt="Variant 3: Macedonian" width="520">
</p>

**Async coordination for PHP 8.4+ that reads like synchronous code.**

Phalanx separates what you want from how it runs. You declare operations as plain PHP classes. Phalanx handles fibers, event loops, worker processes, cancellation, and cleanup. No promise chains. No callback hell. No manual fiber management.

```php
<?php

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
composer require phalanx/core           # Scopes, tasks, concurrency, services, cancellation
composer require phalanx/console        # CLI framework with command routing
composer require phalanx/http           # HTTP server on ReactPHP with routing and SSE
composer require phalanx/parallel       # Worker process pools with IPC and supervisors
composer require phalanx/stream         # Reactive streams with channels and backpressure
composer require phalanx/postgres       # Async PostgreSQL via Amphp with LISTEN/NOTIFY
composer require phalanx/redis          # Async Redis via clue/redis-react with pub/sub
composer require phalanx/websocket      # WebSocket connections, gateway, pub/sub topics
composer require phalanx/integrations   # AI (Claude, GPT) and Twilio (SMS, Voice) clients
```

`phalanx/core` is the foundation. Every other package builds on it.

## What makes Phalanx different

**Scoped execution, not global state.** Every operation runs inside a scope that carries services, cancellation tokens, and a disposal stack. When the scope ends, everything cleans up. No reliance on `__destruct` or manual GC management.

**Tasks are classes, not closures.** A task like `FetchUser` has identity -- it shows up in stack traces, can be serialized, retried, and sent to worker processes. Closures are anonymous. Phalanx tasks are named computations.

**Concurrency without ceremony.** Call `$scope->concurrent([...])` and get back an array of results. Call `$scope->race([...])` to get the first result. Call `$scope->settle([...])` to get all outcomes including failures. The scope manages fibers internally.

**Built on proven async.** ReactPHP event loop, React promises, Amphp for Postgres. Phalanx does not reinvent async primitives. It provides the coordination layer above them.

## Requirements

- PHP 8.4+
- `ext-pcntl` for worker process pools (`phalanx/parallel`)
- `ext-pgsql` for PostgreSQL (`phalanx/postgres`)

## Quick start

A CLI tool that queries Docker concurrently:

```php
#!/usr/bin/env php
<?php

use Phalanx\Application;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\ConsoleRunner;

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

use Phalanx\Application;
use Phalanx\Http\Route;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsRouteGroup;

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
