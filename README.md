<p align="center">
  <img src="logo.svg" alt="Phalanx" width="520">
</p>

**Async coordination for PHP 8.4+ that reads like synchronous code.**

Phalanx separates what you want from how it runs. You declare operations as plain PHP classes. Phalanx handles fibers, event loops, worker processes, cancellation, and cleanup. No manual fiber management.

```php
<?php

[$app, $scope] = Application::starting()
    ->providers(new AppBundle())
    ->compile()
    ->boot();

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

$scope->dispose();
$app->shutdown();
```

## Packages

`phalanx/core` is the foundation. Every other package builds on it.

| Package | Description | |
|---------|-------------|---|
| [phalanx/core](https://github.com/havy-tech/phalanx-core) | Scopes, tasks, concurrency, services, cancellation | [![Latest Version](https://img.shields.io/packagist/v/phalanx/core)](https://packagist.org/packages/phalanx/core) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/core/php)](https://packagist.org/packages/phalanx/core) |
| [phalanx/console](https://github.com/havy-tech/phalanx-console) | CLI framework with nested command groups | [![Latest Version](https://img.shields.io/packagist/v/phalanx/console)](https://packagist.org/packages/phalanx/console) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/console/php)](https://packagist.org/packages/phalanx/console) |
| [phalanx/http](https://github.com/havy-tech/phalanx-http) | HTTP server on ReactPHP with routing, middleware, and SSE | [![Latest Version](https://img.shields.io/packagist/v/phalanx/http)](https://packagist.org/packages/phalanx/http) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/http/php)](https://packagist.org/packages/phalanx/http) |
| [phalanx/ai](https://github.com/havy-tech/phalanx-ai) | AI agent runtime -- providers, tools, streaming, structured output | [![Latest Version](https://img.shields.io/packagist/v/phalanx/ai)](https://packagist.org/packages/phalanx/ai) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/ai/php)](https://packagist.org/packages/phalanx/ai) |
| [phalanx/parallel](https://github.com/havy-tech/phalanx-parallel) | Worker process pools with IPC and supervisors | [![Latest Version](https://img.shields.io/packagist/v/phalanx/parallel)](https://packagist.org/packages/phalanx/parallel) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/parallel/php)](https://packagist.org/packages/phalanx/parallel) |
| [phalanx/stream](https://github.com/havy-tech/phalanx-stream) | Reactive streams with channels and backpressure | [![Latest Version](https://img.shields.io/packagist/v/phalanx/stream)](https://packagist.org/packages/phalanx/stream) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/stream/php)](https://packagist.org/packages/phalanx/stream) |
| [phalanx/postgres](https://github.com/havy-tech/phalanx-postgres) | Async PostgreSQL via amphp/postgres with LISTEN/NOTIFY | [![Latest Version](https://img.shields.io/packagist/v/phalanx/postgres)](https://packagist.org/packages/phalanx/postgres) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/postgres/php)](https://packagist.org/packages/phalanx/postgres) |
| [phalanx/ws-server](https://github.com/havy-tech/phalanx-ws-server) | WebSocket server connections, gateway, and pub/sub topics | [![Latest Version](https://img.shields.io/packagist/v/phalanx/ws-server)](https://packagist.org/packages/phalanx/ws-server) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/ws-server/php)](https://packagist.org/packages/phalanx/ws-server) |
| [phalanx/network](https://github.com/havy-tech/phalanx-network) | Network scanning, probing, WOL, and service discovery | *in progress* |
| [phalanx/filesystem](https://github.com/havy-tech/phalanx-filesystem) | Async file operations with resource-governed FilePool | *in progress* |
| [phalanx/ssh](https://github.com/havy-tech/phalanx-ssh) | SSH command execution, SFTP, and tunnel management | *in progress* |
| [phalanx/ui](https://github.com/havy-tech/phalanx-ui) | Frontend bridge — OpenAPI generation, Kubb integration, signal-based reactivity | *in progress* |
| [phalanx/cdp](https://github.com/havy-tech/phalanx-cdp) | Chrome DevTools Protocol client | *in progress* |

## What makes Phalanx different

**Scoped execution, not global state.** Every operation runs inside a scope that carries services, cancellation tokens, and a disposal stack. When the scope ends, everything cleans up deterministically.

**Tasks are classes, not closures.** A task like `FetchUser` has identity -- it shows up in stack traces, can be serialized, retried, and sent to worker processes. Phalanx tasks are named computations you can inspect, compose, and dispatch.

**Concurrency without ceremony.** Call `$scope->concurrent([...])` and get back an array of results. Call `$scope->race([...])` to get the first result. Call `$scope->settle([...])` to get all outcomes including failures. The scope manages fibers internally.

**Built on proven async.** ReactPHP event loop, React promises, Amphp for Postgres. The PHP async ecosystem has matured into a collection of battle-tested, reliable libraries. Phalanx builds a coordination layer on top of them -- composing these foundations into a unified execution model in a way we haven't seen done before in async PHP.

**Actively iterated, converging fast.** The API has been through multiple design passes -- exploring different patterns for task definition, service wiring, and handler configuration. Each iteration has simplified the surface while increasing capability. The current shape is the result of that process, and it's landing in a place that feels right.

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
    'ps'     => PsCommand::class,
    'images' => ImagesCommand::class,
    'pull'   => PullCommand::class,
]);

$runner = ConsoleRunner::withCommands($app, $commands);
exit($runner->run($argv));
```

An HTTP server with WebSocket support:

```php
#!/usr/bin/env php
<?php

use Phalanx\Application;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsRouteGroup;

$gateway = new WsGateway();

$app = Application::starting()
    ->providers(new AppBundle($gateway))
    ->compile();

$routes = RouteGroup::of([
    'GET /'      => HomePage::class,
    'POST /dump' => DumpReceiver::class,
]);

$ws = WsRouteGroup::of([
    '/live'    => LiveStreamHandler::class,
    '/metrics' => MetricsStreamHandler::class,
], gateway: $gateway);

Runner::from($app)
    ->withRoutes($routes)
    ->withWebsockets($ws)
    ->run('0.0.0.0:8080');
```

## CLI with nested command groups

Commands support nesting. Group related operations under a shared name:

```php
<?php

$commands = CommandGroup::of([
    'serve' => ServeHttp::class,
    'net' => CommandGroup::of([
        'scan'     => ScanSubnet::class,
        'probe'    => ProbePort::class,
        'wake'     => WakeHost::class,
        'discover' => DiscoverDevices::class,
    ], description: 'Network operations'),
    'ssh' => CommandGroup::of([
        'run'    => RunRemoteCommand::class,
        'deploy' => DeployApplication::class,
    ], description: 'Remote SSH operations'),
]);

$runner = ConsoleRunner::withCommands($app, $commands);
exit($runner->run($argv));
```

```bash
php app.php serve --port=8080
php app.php net scan 192.168.1.0/24 --concurrency=50
php app.php net --help
php app.php ssh deploy prod release.tar.gz /var/www
```

Groups can nest arbitrarily deep. Typing a group name without a subcommand shows its help.

## Development

```bash
composer install          # Install all dependencies
composer test             # PHPUnit across all packages
composer analyse          # PHPStan level 8
composer check            # analyse + rector:dry + examples:lint + test (full CI gate)
```

## License

MIT
