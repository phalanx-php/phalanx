<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Aegis

Async coordination for PHP 8.4+. Built on [ReactPHP](https://reactphp.org/) and [AMPHP](https://amphp.org/). Scope hierarchy manages concurrency, cancellation, and cleanup. You write named computations. The scope handles the machinery.

Fibers, property hooks, lazy objects, asymmetric visibility -- PHP 8.4 is a different language. Phalanx treats these as the foundation, not optional extras.

[Read more](https://open.substack.com/pub/jhavenz/p/when-php-computations-have-names) | [Contributing](https://github.com/phalanx-php/phalanx-aegis/blob/main/CONTRIBUTING.md)

## Installation

```bash
composer require phalanx/aegis
```

> [!NOTE]
> Requires PHP 8.4 or later.

## Quick Start

```php
<?php

[$app, $scope] = Application::starting()
    ->providers(new AppBundle())
    ->compile()
    ->boot();

$result = $scope->execute(Task::of(static fn(ExecutionScope $s) =>
    $s->service(OrderService::class)->process(42)
));

$scope->dispose();
$app->shutdown();
```

## How It Works

Phalanx's model: **Application -> Scope -> Tasks**.

```
Application::starting($context)
    -> compile()           // Validate service graph, create app
    -> startup()           // Run startup hooks, enable shutdown handlers
    -> createScope()       // Create ExecutionScope
    -> execute(Task)       // Run typed tasks
    -> dispose()           // Cleanup scope resources
    -> shutdown()          // Cleanup app resources
```

Every task implements `Scopeable` or `Executable`—single-method interfaces:

```php
<?php

// Tasks needing only service resolution
interface Scopeable {
    public function __invoke(Scope $scope): mixed;
}

// Tasks needing execution primitives (concurrency, cancellation)
interface Executable {
    public function __invoke(ExecutionScope $scope): mixed;
}
```

### Scope Hierarchy

Phalanx decomposes scope into granular capability interfaces:

```
Scope                 service(), attribute(), trace()
Suspendable           await(PromiseInterface): mixed
Cancellable           isCancelled, throwIfCancelled(), cancellation()
Disposable            onDispose(), dispose()

TaskScope             extends Scope + Suspendable + Cancellable + Disposable
                      execute(), executeFresh()

TaskExecutor          concurrent(), race(), any(), map(), settle()
                      timeout(), retry(), delay(), defer(), singleflight(), inWorker()

ExecutionScope        extends TaskScope + TaskExecutor + StreamContext
```

| Interface | Use when... |
|-----------|------------|
| `Scope` | You only need service resolution (file loaders, middleware) |
| `Suspendable` | A service needs `await()` (RedisClient, TwilioRest) |
| `TaskScope` | You compose tasks and need cancellation/disposal (handlers, middleware chains) |
| `ExecutionScope` | You orchestrate concurrent operations (scanners, pipelines, deployment tasks) |

All fiber suspension goes through `$scope->await()`. Raw `React\Async\await()` is only used inside `ExecutionLifecycleScope` internals.

```php
<?php

// Services type-hint what they actually need
final class RedisClient {
    public function __construct(
        private readonly Client $inner,
        private readonly Suspendable $scope,  // only needs await()
    ) {}

    public function get(string $key): mixed {
        return $this->scope->await($this->inner->__call('get', [$key]));
    }
}
```

Domain scopes extend `ExecutionScope` with typed properties:

| Scope | Package | Adds |
|-------|---------|------|
| `CommandScope` | phalanx-archon | `$args`, `$options`, `$commandName` |
| `RequestScope` | phalanx-stoa | `$request`, `$params`, `$query`, `$body` |
| `WsScope` | phalanx-hermes | `$connection`, `$request` |

## The Task System

### Two Ways to Define Tasks

**Quick tasks** for one-offs:

```php
<?php

$task = Task::of(static fn(ExecutionScope $s) => $s->service(UserRepo::class)->find($id));
$user = $scope->execute($task);
```

**Invokable classes** for everything else:

```php
<?php

final readonly class FetchUser implements Scopeable
{
    public function __construct(private int $id) {}

    public function __invoke(Scope $scope): User
    {
        return $scope->service(UserRepo::class)->find($this->id);
    }
}

$user = $scope->execute(new FetchUser(42));
```

The invokable approach gives you:

- **Traceable**: Stack traces show `FetchUser::__invoke`, not `Closure@handler.php:47`
- **Testable**: Mock the scope, invoke the task, assert the result
- **Serializable**: Constructor args are data—queue jobs, distribute across workers
- **Inspectable**: The class name is the identity; constructor args are the inputs

### Behavior via Interfaces

Tasks declare behavior through PHP 8.4 property hooks:

```php
<?php

final class DatabaseQuery implements Scopeable, Retryable, HasTimeout
{
    public RetryPolicy $retryPolicy {
        get => RetryPolicy::exponential(3);
    }

    public float $timeout {
        get => 5.0;
    }

    public function __invoke(Scope $scope): array
    {
        return $scope->service(Database::class)->query($this->sql);
    }
}
```

The behavior pipeline applies automatically: **timeout wraps retry wraps trace wraps work**.

| Interface | Property | Purpose |
|-----------|----------|---------|
| `Retryable` | `RetryPolicy $retryPolicy { get; }` | Automatic retry with policy |
| `HasTimeout` | `float $timeout { get; }` | Automatic timeout in seconds |
| `HasPriority` | `int $priority { get; }` | Priority queue ordering |
| `UsesPool` | `UnitEnum $pool { get; }` | Pool-aware scheduling |
| `Traceable` | `string $traceName { get; }` | Custom trace label |

## Concurrency Primitives

| Method | Behavior | Returns |
|--------|----------|---------|
| `concurrent($tasks)` | Run all concurrently, wait for all | Array of results |
| `race($tasks)` | First to settle (success or failure) | Single result |
| `any($tasks)` | First success (ignores failures) | Single result |
| `map($items, $fn, $limit)` | Bounded concurrency over collection | Array of results |
| `settle($tasks)` | Run all, collect outcomes including failures | SettlementBag |
| `timeout($seconds, $task)` | Run with deadline | Result or throws |
| `series($tasks)` | Sequential execution | Array of results |
| `waterfall($tasks)` | Sequential, passing result forward | Final result |

```php
<?php

// Concurrent fetch
[$customer, $inventory] = $scope->concurrent([
    new FetchCustomer($customerId),
    new ValidateInventory($items),
]);

// First successful response wins (fallback pattern)
$data = $scope->any([
    new FetchFromPrimary($key),
    new FetchFromFallback($key),
]);

// 10,000 items. 10 concurrent fibers.
$results = $scope->map($items, fn($item) => new ProcessItem($item), limit: 10);
```

## Lazy Sequences

Generator-based pipelines. Values flow one at a time -- memory stays flat regardless of dataset size.

```php
<?php

use Phalanx\Task\LazySequence;

$seq = LazySequence::from(static function (ExecutionScope $scope) {
    foreach ($scope->service(OrderRepo::class)->cursor() as $order) {
        yield $order;
    }
});

$totals = $seq
    ->filter(fn(Order $o) => $o->total > 100_00)
    ->map(fn(Order $o) => new OrderSummary($o))
    ->take(50)
    ->toArray();

$result = $scope->execute($totals);
```

Operators are lazy -- nothing runs until a terminal (`toArray`, `reduce`, `first`, `consume`) triggers execution.

## Services

```php
<?php

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class AppBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(DatabasePool::class)
            ->factory(fn() => new DatabasePool($context['db_url']))
            ->onStartup(fn($pool) => $pool->warmUp(5))
            ->onShutdown(fn($pool) => $pool->drain());

        $services->scoped(RequestLogger::class)
            ->lazy()
            ->onDispose(fn($log) => $log->flush());
    }
}
```

| Method | Lifecycle |
|--------|-----------|
| `singleton()` | One instance per application |
| `scoped()` | One instance per scope, disposed with scope |
| `lazy()` | Defer creation until first access (PHP 8.4 lazy ghosts) |

## Cancellation & Retry

```php
<?php

use Phalanx\Concurrency\CancellationToken;
use Phalanx\Concurrency\RetryPolicy;

// Timeout for entire scope
$scope = $app->createScope(CancellationToken::timeout(30.0));

// Task-level timeout
$result = $scope->timeout(5.0, new SlowApiCall($id));

// Retry with exponential backoff
$result = $scope->retry(
    new FetchFromApi($url),
    RetryPolicy::exponential(attempts: 3)
);

// Check cancellation within tasks (use Executable when you need ExecutionScope)
final class LongRunningTask implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        foreach ($this->chunks as $chunk) {
            $scope->throwIfCancelled();
            $this->process($chunk);
        }
        return $this->result;
    }
}
```

## Tracing

```bash
PHALANX_TRACE=1 php server.php
```

```
    0ms  STRT  compiling
    4ms  STRT  startup
    6ms  CON>    concurrent(2)
    7ms  EXEC    FetchCustomer
    8ms  DONE    FetchCustomer  +0.61ms
   19ms  CON<    concurrent(2) joined  +12.8ms

0 svc  4.0MB peak  0 gc  39.8ms total
```

## Deterministic Cleanup

```php
<?php

$scope = $app->createScope();
$scope->onDispose(fn() => $connection->close());

// Your task code...

$scope->dispose();  // Cleanup fires in reverse order
```

## Packages

| Package | Purpose |
|---------|---------|
| [phalanx/aegis](https://github.com/phalanx-php/phalanx-aegis) | Scope hierarchy, tasks, services, cancellation |
| [phalanx/stoa](https://github.com/phalanx-php/phalanx-stoa) | HTTP server and routing |
| [phalanx/archon](https://github.com/phalanx-php/phalanx-archon) | CLI commands |
| [phalanx/styx](https://github.com/phalanx-php/phalanx-styx) | Reactive streams, backpressure |
| [phalanx/athena](https://github.com/phalanx-php/phalanx-athena) | AI agent runtime |
| [phalanx/theatron](https://github.com/phalanx-php/phalanx-theatron) | Terminal UI |
| [phalanx/hermes](https://github.com/phalanx-php/phalanx-hermes) | WebSocket server and client |
| [phalanx/hydra](https://github.com/phalanx-php/phalanx-hydra) | Worker process parallelism |
| [phalanx/eidolon](https://github.com/phalanx-php/phalanx-eidolon) | Frontend bridge, OpenAPI |
| [phalanx/skopos](https://github.com/phalanx-php/phalanx-skopos) | Dev server orchestrator |
| [phalanx/postgres](https://github.com/phalanx-php/phalanx-postgres) | Async PostgreSQL |
| [phalanx/argos](https://github.com/phalanx-php/phalanx-argos) | Network utilities |
| [phalanx/grammata](https://github.com/phalanx-php/phalanx-grammata) | Async filesystem |
| [phalanx/enigma](https://github.com/phalanx-php/phalanx-enigma) | SSH client |
