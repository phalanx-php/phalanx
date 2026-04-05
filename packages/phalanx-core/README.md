<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Core - Async PHP

Phalanx is an async coordination library for PHP 8.4+. It replaces callbacks with typed tasks—named computations that carry their own identity, behavior, and lifecycle through a unified execution model built on ReactPHP.

[Substack write up](https://open.substack.com/pub/jhavenz/p/when-php-computations-have-names)

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [How It Works](#how-it-works)
  - [Scope Hierarchy](#scope-hierarchy)
- [The Task System](#the-task-system)
  - [Two Ways to Define Tasks](#two-ways-to-define-tasks)
  - [Behavior via Interfaces](#behavior-via-interfaces)
- [Concurrency Primitives](#concurrency-primitives)
- [Lazy Sequences](#lazy-sequences)
- [Route Groups](#route-groups)
  - [Loading Routes](#loading-routes)
  - [Composing Route Groups](#composing-route-groups)
- [Command Groups](#command-groups)
  - [Running Commands](#running-commands)
- [Services](#services)
- [Cancellation & Retry](#cancellation--retry)
- [Tracing](#tracing)
- [Deterministic Cleanup](#deterministic-cleanup)
- [Examples](#examples)

## Installation

```bash
composer require phalanx/core
```

Requires PHP 8.4+.

## Quick Start

```php
<?php

$app = Application::starting()->providers(new AppBundle())->compile();
$app->startup();

$scope = $app->createScope();

$result = $scope->execute(Task::of(static fn(ExecutionScope $s) =>
    $s->service(OrderService::class)->process(42)
));

$scope->dispose();
$app->shutdown();
```

**Note:** Service classes like `OrderService`, `UserRepo`, `DatabasePool` in these examples are illustrative. Phalanx Core provides the coordination primitives—your application brings the domain logic.

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
| `CommandScope` | phalanx-console | `$args`, `$options`, `$commandName` |
| `RequestScope` | phalanx-http | `$request`, `$params`, `$query`, `$body` |
| `WsScope` | phalanx-ws-server | `$connection`, `$request` |

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

`LazySequence` processes large datasets through generator-based pipelines. Values flow one at a time—memory stays flat regardless of dataset size.

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

Operators (`map`, `filter`, `take`, `chunk`) are lazy—nothing runs until a terminal (`toArray`, `reduce`, `first`, `consume`) triggers execution. Two mapping modes handle different workloads:

| Method | Execution Model |
|--------|----------------|
| `mapConcurrent($fn, $concurrency)` | Fibers in the current process |
| `mapParallel($fn, $concurrency)` | Worker processes via IPC |

## Route Groups

Typed collections of HTTP routes with `RouteGroup`. Route handlers receive `RequestScope`—a scope decorator with typed route parameters, query strings, and request body access:

```php
<?php
// routes/api.php

use Phalanx\Http\Route;
use Phalanx\Http\RouteGroup;
use Phalanx\Scope;

return static fn(Scope $s): RouteGroup => RouteGroup::of([
    'GET /users'      => new Route(fn: new ListUsers()),
    'GET /users/{id}' => new Route(fn: new ShowUser()),
    'POST /users'     => new Route(fn: new CreateUser()),
]);
```

### Loading Routes

```php
<?php

use Phalanx\Http\Runner;

$runner = Runner::from($app, requestTimeout: 30.0)
    ->withRoutes(__DIR__ . '/routes');
$runner->run('0.0.0.0:8080');
```

### Composing Route Groups

```php
<?php

$api = RouteGroup::create()
    ->merge($publicRoutes)
    ->mount('/admin', $adminRoutes)
    ->wrap(new AuthMiddleware());
```

## Command Groups

Typed collections of CLI commands with `CommandGroup`. Command handlers receive `CommandScope`—a scope decorator with typed arguments and options:

```php
<?php
// commands/db.php

use Phalanx\Console\Arg;
use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\Opt;

return CommandGroup::of([
    'migrate' => new Command(
        fn: new RunMigrations(),
        desc: 'Run database migrations',
    ),
    'db:seed' => new Command(
        fn: new SeedDatabase(),
        desc: 'Seed the database',
        opts: [Opt::flag('fresh', 'f', 'Truncate tables first')],
    ),
]);
```

### Running Commands

```php
<?php

use Phalanx\Console\ConsoleRunner;

$runner = ConsoleRunner::withCommands($app, __DIR__ . '/commands');
exit($runner->run($argv));
```

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

## Authentication

Phalanx provides core auth primitives that transport packages (`phalanx/http`, `phalanx/ws-server`) build on.

### Guard Interface

Implement `Guard` to extract identity from a request:

```php
<?php

use Phalanx\Auth\AuthContext;
use Phalanx\Auth\Guard;
use Psr\Http\Message\ServerRequestInterface;

final class BearerTokenGuard implements Guard
{
    public function resolve(ServerRequestInterface $request): ?AuthContext
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $user = $this->validateToken(substr($header, 7));
        return $user !== null
            ? AuthContext::authenticated($user, substr($header, 7))
            : null;
    }
}
```

### Identity Interface

Your user model implements `Identity`:

```php
<?php

use Phalanx\Auth\Identity;

final class AppUser implements Identity
{
    public string|int $id { get => $this->userId; }

    public function __construct(private readonly int $userId) {}
}
```

### AuthContext

The resolved auth state, carrying identity, token, and abilities:

```php
<?php

use Phalanx\Auth\AuthContext;

$auth = AuthContext::authenticated($user, $token, ['admin', 'write']);

$auth->isAuthenticated;      // true
$auth->identity->id;         // user ID
$auth->can('admin');         // true
$auth->token();              // the raw token string

$guest = AuthContext::guest();
$guest->isAuthenticated;     // false
```

### Authenticate Middleware

Use the built-in `Authenticate` middleware with any `Guard`:

```php
<?php

use Phalanx\Auth\Authenticate;

$routes = RouteGroup::of([...])->wrap(new Authenticate(new BearerTokenGuard()));
```

## Deterministic Cleanup

```php
<?php

$scope = $app->createScope();
$scope->onDispose(fn() => $connection->close());

// Your task code...

$scope->dispose();  // Cleanup fires in reverse order
```

## Examples

### Docker CLI

A practical CLI app demonstrating `CommandScope` with typed arguments and options, `ServiceBundle` wiring, and invokable task classes.

```bash
php examples/docker-cli/docker-cli.php ps -a
php examples/docker-cli/docker-cli.php images
php examples/docker-cli/docker-cli.php logs nginx
```
