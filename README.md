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

`phalanx/aegis` is the foundation. Every other package builds on it.

| Package | Description | |
|---------|-------------|---|
| [phalanx/aegis](https://github.com/phalanx-php/phalanx-aegis) | Scopes, tasks, concurrency, services, cancellation | [![Latest Version](https://img.shields.io/packagist/v/phalanx/aegis)](https://packagist.org/packages/phalanx/aegis) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/aegis/php)](https://packagist.org/packages/phalanx/aegis) |
| [phalanx/archon](https://github.com/phalanx-php/phalanx-archon) | CLI framework with nested command groups | [![Latest Version](https://img.shields.io/packagist/v/phalanx/archon)](https://packagist.org/packages/phalanx/archon) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/archon/php)](https://packagist.org/packages/phalanx/archon) |
| [phalanx/stoa](https://github.com/phalanx-php/phalanx-stoa) | HTTP server on ReactPHP with routing, middleware, and SSE | [![Latest Version](https://img.shields.io/packagist/v/phalanx/stoa)](https://packagist.org/packages/phalanx/stoa) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/stoa/php)](https://packagist.org/packages/phalanx/stoa) |
| [phalanx/athena](https://github.com/phalanx-php/phalanx-athena) | AI agent runtime -- providers, tools, streaming, structured output | [![Latest Version](https://img.shields.io/packagist/v/phalanx/athena)](https://packagist.org/packages/phalanx/athena) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/athena/php)](https://packagist.org/packages/phalanx/athena) |
| [phalanx/hydra](https://github.com/phalanx-php/phalanx-hydra) | Worker process pools with IPC and supervisors | [![Latest Version](https://img.shields.io/packagist/v/phalanx/hydra)](https://packagist.org/packages/phalanx/hydra) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/hydra/php)](https://packagist.org/packages/phalanx/hydra) |
| [phalanx/styx](https://github.com/phalanx-php/phalanx-styx) | Reactive streams with channels and backpressure | [![Latest Version](https://img.shields.io/packagist/v/phalanx/styx)](https://packagist.org/packages/phalanx/styx) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/styx/php)](https://packagist.org/packages/phalanx/styx) |
| [phalanx/postgres](https://github.com/phalanx-php/phalanx-postgres) | Async PostgreSQL via amphp/postgres with LISTEN/NOTIFY | [![Latest Version](https://img.shields.io/packagist/v/phalanx/postgres)](https://packagist.org/packages/phalanx/postgres) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/postgres/php)](https://packagist.org/packages/phalanx/postgres) |
| [phalanx/hermes](https://github.com/phalanx-php/phalanx-hermes) | WebSocket server connections, gateway, and pub/sub topics | [![Latest Version](https://img.shields.io/packagist/v/phalanx/hermes)](https://packagist.org/packages/phalanx/hermes) [![PHP](https://img.shields.io/packagist/dependency-v/phalanx/hermes/php)](https://packagist.org/packages/phalanx/hermes) |
| [phalanx/argos](https://github.com/phalanx-php/phalanx-argos) | Network scanning, probing, WOL, and service discovery | *in progress* |
| [phalanx/grammata](https://github.com/phalanx-php/phalanx-grammata) | Async file operations with resource-governed FilePool | *in progress* |
| [phalanx/enigma](https://github.com/phalanx-php/phalanx-enigma) | SSH command execution, SFTP, and tunnel management | *in progress* |
| [phalanx/eidolon](https://github.com/phalanx-php/phalanx-eidolon) | Frontend bridge — OpenAPI generation, Kubb integration, signal-based reactivity | *in progress* |
| [phalanx/cdp](https://github.com/phalanx-php/phalanx-cdp) | Chrome DevTools Protocol client | *in progress* |

## What makes Phalanx different

**Scoped execution, not global state.** Every operation runs inside a scope that carries services, cancellation tokens, and a disposal stack. When the scope ends, everything cleans up deterministically.

**Tasks are classes, not closures.** A task like `FetchUser` has identity -- it shows up in stack traces, can be serialized, retried, and sent to worker processes. Phalanx tasks are named computations you can inspect, compose, and dispatch.

**Concurrency without ceremony.** Call `$scope->concurrent([...])` and get back an array of results. Call `$scope->race([...])` to get the first result. Call `$scope->settle([...])` to get all outcomes including failures. The scope manages fibers internally.

**Built on proven async.** ReactPHP event loop, React promises, Amphp for Postgres. The PHP async ecosystem has matured into a collection of battle-tested, reliable libraries. Phalanx builds a coordination layer on top of them -- composing these foundations into a unified execution model in a way we haven't seen done before in async PHP.

**Actively iterated, converging fast.** The API has been through multiple design passes -- exploring different patterns for task definition, service wiring, and handler configuration. Each iteration has simplified the surface while increasing capability. The current shape is the result of that process, and it's landing in a place that feels right.

## Requirements

- PHP 8.4+
- `ext-pcntl` for worker process pools (`phalanx/hydra`)
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

## Testing

The default `composer test` run excludes tests that need external services. Groups excluded by default:

- `daemon` -- requires a running [daemon8](https://daemon8.ai) instance and the daemon8 PHP SDK
- `live` -- requires live network services (real HTTP calls to external endpoints)

To run a specific excluded group:

```bash
php vendor/bin/phpunit --group daemon
php vendor/bin/phpunit --group live
```

Other groups that run by default: `smoke` (heavier concurrency workloads), `architecture` (code style/lint checks).

## License

MIT
