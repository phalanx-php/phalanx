<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Aegis

**Async PHP without the async.**

Three HTTP calls in parallel. Cancellation that actually fires. Memory that stays flat across millions of rows. Stack traces that name your work instead of `Closure@handler.php:47`.

Phalanx is built on [ReactPHP](https://reactphp.org/) and [AMPHP](https://amphp.org/). It separates *what you want to happen* from *how it runs*. You write named computations. The scope handles the fibers, the event loop, cancellation, and cleanup.

PHP 8.4 -- fibers, property hooks, asymmetric visibility, lazy proxies -- is the foundation, not an afterthought.

---

## Proof

```php
<?php

[$app, $scope] = Application::starting()
    ->providers(new AppBundle())
    ->compile()
    ->boot();

[$customer, $inventory, $quote] = $scope->concurrent([
    new FetchCustomer($id),
    new ValidateInventory($items),
    new PriceQuote($items),
]);
```

Run it with tracing:

```bash
PHALANX_TRACE=1 php server.php
```

```
    0ms  STRT  compiling
    4ms  STRT  startup
    6ms  CON>    concurrent(3)
    7ms  EXEC    FetchCustomer
    7ms  EXEC    ValidateInventory
    8ms  EXEC    PriceQuote
   12ms  DONE    PriceQuote          +4.1ms
   16ms  DONE    FetchCustomer       +9.2ms
   18ms  DONE    ValidateInventory   +10.4ms
   19ms  CON<    concurrent(3) joined  +12.8ms

3 svc  4.0MB peak  0 gc  39.8ms total
```

Three tasks. One process. Bounded memory. Visible execution.

---

<details>
<summary><b>The deadlocks you can't see at any single call site</b> — what goes wrong in production async PHP, and what the scope does about it</summary>

Async PHP is mostly fine until you compose it. Two services that are correct in isolation can deadlock when one calls the other -- and the call site that triggers it looks completely innocuous. These are the ones that bite real codebases.

### The classes that look fine on their own

```php
<?php

final class OrderRepository
{
    public function __construct(
        private ConnectionPool $pool,
        private AuditService $audit,
    ) {}

    public function createOrder(array $data): Order
    {
        $conn = $this->pool->acquire();
        $conn->beginTransaction();
        $order = $conn->insert('orders', $data);

        $this->audit->log('order.created', $order->id);   // looks innocent

        $conn->commit();
        $this->pool->release($conn);
        return $order;
    }
}

final class AuditService
{
    public function __construct(private ConnectionPool $pool) {}

    public function log(string $event, int $entityId): void
    {
        $conn = $this->pool->acquire();   // same pool
        $conn->insert('audit_log', ['event' => $event, 'entity_id' => $entityId]);
        $this->pool->release($conn);
    }
}
```

Pool size 10. Ten concurrent fibers each hold a connection in `createOrder` and call `audit->log`, which tries to acquire a second from the same pool. Nothing is available, nothing will be released until each fiber gets the second connection it's waiting for. The server hangs with no error. Unit tests passed. The author of `AuditService` had no idea it was being called inside an existing acquisition.

### What goes wrong, and what Phalanx does about it

| What goes wrong | Why it bites | What Phalanx gives you |
|---|---|---|
| Nested pool acquire across services exhausts a shared pool | Each class is correct alone; the deadlock only appears when concurrency >= pool size | Pass the connection through the scope; bound concurrency with `$scope->map($items, $fn, limit: N)` so you can't outrun your pool |
| Awaiting external I/O while inside an open DB transaction holds row locks across the suspension | The row lock is in the database; PHP can't see it; sync PHP-FPM never had this problem | `$scope->await()` is the only suspension primitive in app code -- it's grep-able; the scope-level deadline kills the request before the lock starves others |
| Two parallel fibers compute cache `A` (which needs `B`) and cache `B` (which needs `A`); both hold one lock and wait for the other | The lock is hidden inside the cache library | `$scope->singleflight($key, $task)` collapses concurrent duplicates behind one execution; resolve dependencies *before* you enter the cache callback |
| A bug in a transitive dependency swallows an exception; a deferred is never resolved; the fiber suspends forever; memory grows | Every awaiting fiber is one more leaked stack | `$scope->await()` races every promise against the scope's `CancellationToken`; a scope-level deadline guarantees every fiber eventually resumes, even with an exception |
| Non-fiber-safe C extension (gRPC, some DB drivers) holds a pthread mutex across a fiber suspension; the next fiber blocks the entire process | The event loop is dead, not just one fiber | `$scope->inWorker($task)` runs the task in a child process so the extension can't poison the event loop |
| Pool isn't FIFO; faster fibers always win the race; slower fibers timeout indefinitely | The pool is "healthy" but the application isn't | Bound concurrency at the call site with `map(..., limit: N)` instead of relying on pool fairness; `$scope->settle()` lets you see *which* tasks failed |

The point isn't that Phalanx prevents every footgun. It's that the patterns that cause these problems -- raw `await()` scattered across services, untracked transactions, untimed promises, ad-hoc pool acquisition -- aren't the path of least resistance. The scope is.

</details>

---

## Install

```bash
composer require phalanx/aegis
```

> [!NOTE]
> Requires PHP 8.4 or later.

## Mental model

```
Application  ->  Scope  ->  Tasks
```

The **Application** owns long-lived services and the trace. A **Scope** is the unit of work -- a request, a command, a worker turn -- and carries cancellation, disposal, and access to services. **Tasks** are the things that actually run; the scope orchestrates them.

```php
<?php

[$app, $scope] = Application::starting()
    ->providers(new AppBundle())
    ->compile()
    ->boot();

$result = $scope->execute(new ProcessOrder($id));

$scope->dispose();   // scoped services and disposal hooks fire in reverse order
$app->shutdown();    // singleton shutdown hooks fire
```

## Concurrency

If you've used ReactPHP promises or AMPHP futures, you'll be right at home -- `concurrent`, `race`, `any`, `map` are the same patterns, exposed as scope methods that thread cancellation and tracing through every call.

```php
<?php

// Bounded concurrent fetch -- 10,000 items, 10 fibers in flight at any moment.
$results = $scope->map(
    items: $orderIds,
    fn: static fn(int $id): Executable => new FetchOrder($id),
    limit: 10,
);

// Fallback pattern -- first cache hit wins, network is the backstop.
$value = $scope->any([
    new FetchFromRedis($key),
    new FetchFromS3($key),
    new FetchFromOrigin($key),
]);

// Partial-failure tolerant fan-out.
$bag = $scope->settle([
    'primary'   => new SyncToPrimary($order),
    'analytics' => new EmitAnalytics($order),
    'webhook'   => new NotifyWebhook($order),
]);

if ($bag->allOk) {
    return $bag->values;
}

logger()->warning('partial sync', ['failed' => $bag->errKeys]);
return $bag->extract(['primary' => null, 'analytics' => [], 'webhook' => false]);
```

> **Concurrent != Parallel.** `concurrent`, `map`, `race`, `any` interleave fibers in a single process. `inWorker` runs in a child process. Same API, different runtime -- the call site decides.

<details>
<summary><b>Full primitives reference</b> — every method on the scope, what it does, what it returns</summary>

Every method takes `Scopeable | Executable` tasks -- a closure-backed `Task::of(...)` or a named class.

| Method | Behavior | Returns |
|--------|----------|---------|
| `concurrent($tasks)` | Run all concurrently, wait for all, throw on first failure | Array of results |
| `settle($tasks)` | Run all, collect outcomes including failures | `SettlementBag` |
| `race($tasks)` | First to settle wins (success or failure) | Single result |
| `any($tasks)` | First success wins (failures ignored unless all fail) | Single result |
| `map($items, $fn, limit: 10)` | Bounded concurrent map over a collection | Array of results |
| `series($tasks)` | Sequential | Array of results |
| `waterfall($tasks)` | Sequential, each receives the previous result | Final result |
| `timeout($seconds, $task)` | Run with a deadline | Result, or throws |
| `retry($task, $policy)` | Run with retry policy | Result, or throws after exhaustion |
| `singleflight($key, $task)` | De-duplicate concurrent calls behind a key | Shared result |
| `inWorker($task)` | Run in a child process (parallel, not concurrent) | Result |

</details>

## Tasks

For trivial one-offs, a static closure is fine:

```php
<?php

$user = $scope->execute(
    Task::of(static fn(Scope $s) => $s->service(UserRepo::class)->find($id))
);
```

`Task::of()` enforces `static` at runtime via reflection. Non-static closures capture `$this` and create reference cycles that leak in long-running processes.

For anything with meaning beyond a single call site -- anything you want to log, test, queue, or read in a stack trace -- use a named invokable:

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

What you get back:

- **Traceable.** Stack traces show `FetchUser::__invoke`, not `Closure@handler.php:47`.
- **Testable.** Construct with mocks, invoke, assert. No framework required.
- **Serializable.** Constructor args are data -- queue it, ship it to a worker, replay it.
- **Inspectable.** The class name *is* the identity. Logs, metrics, traces all carry it.

`Scopeable` tasks receive `Scope` (service resolution only). `Executable` tasks receive `ExecutionScope` (full concurrency primitives). Pick the narrowest interface that covers what the task actually does.

## Behavior via interfaces

Tasks declare cross-cutting behavior through PHP 8.4 property hooks. The scope reads the interface, the pipeline applies automatically.

```php
<?php

final class DatabaseQuery implements Scopeable, Retryable, HasTimeout, Traceable
{
    public RetryPolicy $retryPolicy {
        get => RetryPolicy::exponential(attempts: 3)
            ->retryingOn(ConnectionException::class);
    }

    public float $timeout {
        get => 5.0;
    }

    public string $traceName {
        get => "db.query[{$this->table}]";
    }

    public function __construct(
        private string $table,
        private string $sql,
    ) {}

    public function __invoke(Scope $scope): array
    {
        return $scope->service(Database::class)->query($this->sql);
    }
}
```

`timeout` wraps `retry` wraps `trace` wraps your work. The pipeline is composed once and reused on every dispatch.

<details>
<summary>All behavioral interfaces</summary>

| Interface | Property | Adds |
|-----------|----------|------|
| `Retryable` | `RetryPolicy $retryPolicy { get; }` | Automatic retry with policy |
| `HasTimeout` | `float $timeout { get; }` | Per-task deadline |
| `HasPriority` | `int $priority { get; }` | Priority queue ordering |
| `UsesPool` | `UnitEnum $pool { get; }` | Pool-aware scheduling |
| `Traceable` | `string $traceName { get; }` | Custom trace label |
| `SelfDescribed` | `string $description { get; }` | Human-readable description |
| `Tagged` | `list<string> $tags { get; }` | Classification labels |

</details>

## Cancellation, deadlines, retry

Every scope carries a `CancellationToken`. `$scope->await()` races every promise against it -- so cancellation is not advisory, it lands.

```php
<?php

// Hard deadline for the entire scope.
$scope = $app->createScope(CancellationToken::timeout(30.0));

// Per-task deadline.
$result = $scope->timeout(5.0, new SlowApiCall($id));

// Retry with exponential backoff and jitter.
$result = $scope->retry(
    new FetchFromApi($url),
    RetryPolicy::exponential(attempts: 3)->retryingOn(NetworkException::class),
);

// Cooperative cancellation in long loops.
final class ProcessChunks implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        $count = 0;
        foreach ($this->chunks as $chunk) {
            $scope->throwIfCancelled();
            $count += $this->process($chunk);
        }
        return $count;
    }
}
```

`RetryPolicy::exponential()`, `linear()`, and `fixed()` are first-class. Jitter is built in. `CancelledException` is never retried.

## Lazy sequences

`LazySequence` is a generator-driven pipeline. Values flow one at a time, operators are lazy, terminals trigger execution. Memory stays flat regardless of dataset size -- process a million-row cursor without holding a million rows.

```php
<?php

use Phalanx\Task\LazySequence;

$summary = LazySequence::from(static function (ExecutionScope $scope) {
    foreach ($scope->service(OrderRepo::class)->cursor() as $order) {
        yield $order;
    }
})
    ->filter(static fn(Order $o) => $o->total > 100_00)
    ->map(static fn(Order $o) => new OrderSummary($o))
    ->take(50)
    ->toArray();

$top50 = $scope->execute($summary);
```

Operators: `map`, `filter`, `take`, `chunk`, `mapConcurrent($fn, concurrency: 10)`, `mapParallel($fn, concurrency: 4)`. Terminals: `toArray`, `reduce`, `first`, `consume`. Cancellation is checked between every yield.

## Services

Register only things with state or lifecycle -- repositories, clients, pools, loggers. Pure helpers and value objects belong in `new`, not in a container.

```php
<?php

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class AppBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(DatabasePool::class)
            ->factory(static fn() => new DatabasePool($context['db_url']))
            ->onStartup(static fn(DatabasePool $pool) => $pool->warmUp(5))
            ->onShutdown(static fn(DatabasePool $pool) => $pool->drain());

        $services->scoped(RequestLogger::class)
            ->lazy()
            ->onDispose(static fn(RequestLogger $log) => $log->flush());
    }
}
```

| Lifetime | Lifecycle |
|----------|-----------|
| `singleton()` | One instance per application; `onStartup` / `onShutdown` |
| `scoped()` | One instance per scope; `onDispose` fires in reverse order at scope end |
| `eager()` | Singleton, instantiated at boot rather than first access |
| `lazy()` | Defer creation until first property access -- PHP 8.4 lazy ghosts |

All configuration flows through `array $context` from Symfony Runtime. `getenv()` is forbidden in service bundles and application code; the explicit context flow is what makes the system testable and fiber-safe.

<details>
<summary><b>Scope hierarchy</b> — the capability interfaces and when to type-hint each one</summary>

The scope is decomposed into capability interfaces. Type-hint the narrowest one that covers what the dependency actually needs.

```
Scope                 service(), attribute(), withAttribute(), trace()
Suspendable           await(PromiseInterface): mixed
Cancellable           isCancelled, throwIfCancelled(), cancellation()
Disposable            onDispose(), dispose()

TaskScope             Scope + Suspendable + Cancellable + Disposable
                      execute(), executeFresh()

TaskExecutor          concurrent(), race(), any(), map(), series(), waterfall(),
                      settle(), timeout(), retry(), delay(), defer(),
                      singleflight(), inWorker()

ExecutionScope        TaskScope + TaskExecutor + StreamContext
```

| Interface | Use when |
|-----------|----------|
| `Scope` | You only need service resolution (loaders, simple middleware) |
| `Suspendable` | A service only needs `await()` (e.g. RedisClient, TwilioRest) |
| `TaskScope` | You compose tasks and need cancellation/disposal |
| `ExecutionScope` | You orchestrate concurrent operations |

```php
<?php

// A service declares exactly what it needs -- nothing more.
final readonly class RedisClient
{
    public function __construct(
        private Client $inner,
        private Suspendable $scope,
    ) {}

    public function get(string $key): mixed
    {
        return $this->scope->await($this->inner->__call('get', [$key]));
    }
}
```

Domain packages extend `ExecutionScope` with typed properties for their context:

| Scope | Package | Adds |
|-------|---------|------|
| `RequestScope` | `phalanx/stoa` | `$request`, `$params`, `$query`, `$body` |
| `CommandScope` | `phalanx/archon` | `$args`, `$options`, `$commandName` |
| `WsScope` | `phalanx/hermes` | `$connection`, `$request` |

All coroutine suspension goes through `$scope->call()`. Raw `Coroutine::sleep`, `Channel::pop`, `WaitGroup::wait`, etc. are used only inside `ExecutionLifecycleScope` internals and stream/transport infrastructure.

</details>

## Pool & Scope Discipline

The supervisor gives you cheap concurrency. Cheap concurrency exposes pool-management mistakes that don't fail loudly until production. These rules are invariants — every service factory, scope decision, and supervisor primitive obeys them.

### 1. Connection pools are singletons

```php
<?php

$services->singleton(PostgresPool::class)->factory(...);   // correct
$services->scoped(PostgresPool::class)->factory(...);      // wrong — pool storm under concurrent()
```

The pool's job is to bound concurrent connections. Per-scope pools defeat the bound.

### 2. Sibling task scopes don't amplify pool depth

When you `$scope->concurrent([$a, $b])`, each child gets its own scope object with its own scoped-instance map — but the singleton container, including the pool, is shared. Children check out connections from the same pool, bounded by `pool.size`. Two children running `$pool->withConnection(...)` consume two slots.

This is the reason scoped-instance isolation is safe: scoped instances are per-task **state** (request loggers, current-tenant context, scoped caches); resources flow through singletons.

### 3. Pool checkout is per-task and tracked

Connections acquired inside a task body are leases on the active `TaskRun`. They release on task exit. Nested acquire from the same task is `PHX-POOL-001`. Holding a lease across a worker dispatch boundary is `PHX-POOL-002`.

```php
<?php

return $pool->withConnection(static fn ($conn) => $conn->query(...));
```

### 4. Scoped service factories never acquire pool connections

A factory that does `$pool->acquire()` couples service resolution to pool depth. Under `concurrent()` with N children resolving the same scoped service, the pool starves before any work runs.

```php
<?php

// wrong — factory blocks on checkout
$services->scoped(UserRepo::class)->factory(
    static fn (PostgresPool $pool) => new UserRepo($pool->acquire())
);

// correct — factory takes the pool, checkout per method call
$services->scoped(UserRepo::class)->factory(
    static fn (PostgresPool $pool) => new UserRepo($pool)
);
```

### 5. OpenSwoole primitive rules

| Primitive | Rule |
|---|---|
| `Channel(N)` | Use as a semaphore. Never push falsy values (`0`, `''`, `null`, `false`) — consumers can't tell them from `pop()` returning `false` on close. |
| `WaitGroup` | Pre-add the count via `add(count($items))` before spawning. `done()` exactly once per spawn in `finally`. WaitGroups are one-shot — fresh one per primitive call. |
| `ClientPool` | Call `$pool->fill()` at construction. Otherwise N concurrent first-time `get()` calls each invoke `make()` past the configured size. |
| `Coroutine::cancel($cid)` | Returns `false` / sets `Coroutine::isCanceled()` — does not throw. The framework translates at every suspending boundary. User code never calls it directly. |
| `OpenSwoole\Coroutine\PostgreSQL` | Use `query()` with client-side `escapeLiteral`-substituted placeholders. Avoid `prepare()` under pool recycling — segfaults at N > pool size. |
| Coroutine context | `Coroutine::create` gives the child an empty `getContext()`. Spawn sites capture the active scope and re-install via `CoroutineScopeRegistry`. The framework handles this; user code never spawns coroutines directly. |
| `Co::sleep(s)` | Substrate-only — does not honor scope cancellation. Use `$scope->delay(s)`. |
| `OpenSwoole\Process` | Forbidden inside coroutine context. Use `proc_open` with `stream_set_blocking(false)` + `stream_select` for pipe reads. |

### 6. Per-coroutine scope re-installation is mandatory

Every primitive that spawns a coroutine — `concurrent`, `race`, `any`, `map`, `settle`, `defer`, `go`, supervised child tasks, worker dispatch — installs the active scope into the spawned coroutine's context as the first action and clears it in `finally`. Without this, `DeferredScope` (used by long-lived services like `HttpClient`, `LlmClient`, `PostgresPool`) resolves to `null`. The framework owns this; user code that calls `Coroutine::create` directly bypasses it and breaks scope-aware services. Prefer `$scope->go(...)` for supervised background work.

### 6a. `Phalanx::scope()` is a last-resort accessor

For service classes that genuinely cannot accept `$scope` as a constructor or method parameter — third-party adapters with fixed signatures, static loggers in library code — `Phalanx::scope()` returns the currently-installed scope of the running coroutine. `Phalanx::tryScope()` returns null instead of throwing when no scope is installed.

The default rule is *don't use it*. Pass `$scope` explicitly. For service classes you control, prefer constructor-injecting `DeferredScope` so the dependency is visible at the class signature. Reach for the static helper only when neither option applies.

### 7. Singleton vs scoped — the disposition test

Register as **singleton** when:

- It bounds a finite resource (pools, file descriptor caches, network clients with internal pools).
- One instance per app is the right cardinality (logger sinks, metric emitters).
- Replacing the implementation matters in tests.

Register as **scoped** when:

- It carries per-task state that should not leak across tasks (request loggers, current-tenant context, scoped caches keyed by request id).
- It has a meaningful `dispose()` action that should run when the task ends.

If neither applies, don't register it. Call it directly. The container is for resources with lifecycle, not for stateless helpers.

### 8. Ledger backend lives behind an interface

The supervisor's `TaskRun` ledger has two viable backends:

- `InProcessLedger` — PHP array on the supervisor object. Cheapest for single-process workloads. Cooperative non-preemptive scheduling makes it lock-free.
- `SwooleTableLedger` — `Swoole\Table` shared memory. Mandatory once workers participate in the live ledger so the parent and worker children see the same `TaskRun`s.

The supervisor logic is backend-agnostic; pick per deployment. `Swoole\Table` constraints (fixed schema, fixed string column widths, no nested arrays) shape the lease and child-edge schema when targeting that backend.

## Tracing

Visibility is a feature. Set `PHALANX_TRACE=1` and every dispatch, suspend, concurrent group, service init, and disposal lands in a structured trace with timing, depth, and memory samples.

```bash
PHALANX_TRACE=1 php server.php
```

Programmatic access:

```php
<?php

$entries = $scope->trace()->entries();   // list<TraceEntry>
$json    = $scope->trace()->toArray();   // serialized
```

The footer line -- `N svc  XMB peak  N gc  Xms total` -- is the at-a-glance health check: how many services were created, peak resident memory, how many GC runs fired, total wall time.

## Deterministic cleanup

Disposal hooks run in reverse registration order when a scope ends:

```php
<?php

$scope = $app->createScope();
$scope->onDispose(static fn() => $connection->close());
$scope->onDispose(static fn() => $tempFile->unlink());

// ...your work...

$scope->dispose();   // tempFile first, then connection
```

Scopes derived via `$scope->withAttribute(...)` are independent. In long-lived sessions, `dispose()` derived scopes after each unit of work -- undisposed derived scopes leak their cleanup callbacks.

### Context attributes flow to every child

Attributes set with `$scope->withAttribute('request.id', $id)` propagate into every child scope spawned by every concurrency primitive — `concurrent`, `race`, `any`, `map`, `series`, `settle`, `defer`, `go` — and continue flowing into grandchildren. This is how request IDs, tenant IDs, and trace IDs reach deeply-nested concurrent work without manual threading. Children that override an attribute on their *own* derived scope do not mutate the parent.

```php
<?php

$scope = $scope->withAttribute('trace.id', $traceId);
$scope->concurrent([
    'a' => new FetchUser($id),       // sees trace.id
    'b' => new RecordAuditEvent($e), // sees trace.id
]);
```

## The Phalanx ecosystem

Aegis is the core. The rest of the framework is built on the same scope model.

| Package | Purpose |
|---------|---------|
| [phalanx/aegis](https://github.com/phalanx-php/phalanx-aegis) | Scopes, tasks, services, cancellation, retry, tracing |
| [phalanx/stoa](https://github.com/phalanx-php/phalanx-stoa) | HTTP server, routes, middleware |
| [phalanx/archon](https://github.com/phalanx-php/phalanx-archon) | CLI commands, console runner |
| [phalanx/styx](https://github.com/phalanx-php/phalanx-styx) | Reactive streams, channels, backpressure |
| [phalanx/athena](https://github.com/phalanx-php/phalanx-athena) | AI agent runtime, tool dispatch, streaming |
| [phalanx/theatron](https://github.com/phalanx-php/phalanx-theatron) | Async terminal UI |
| [phalanx/hermes](https://github.com/phalanx-php/phalanx-hermes) | WebSocket server and client |
| [phalanx/hydra](https://github.com/phalanx-php/phalanx-hydra) | Worker process parallelism |
| [phalanx/eidolon](https://github.com/phalanx-php/phalanx-eidolon) | Route contracts, OpenAPI generation |
| [phalanx/skopos](https://github.com/phalanx-php/phalanx-skopos) | Dev server orchestrator |
| [phalanx/postgres](https://github.com/phalanx-php/phalanx-postgres) | Async PostgreSQL with pooling |
| [phalanx/argos](https://github.com/phalanx-php/phalanx-argos) | Network utilities |
| [phalanx/grammata](https://github.com/phalanx-php/phalanx-grammata) | Async filesystem with bounded concurrency |
| [phalanx/enigma](https://github.com/phalanx-php/phalanx-enigma) | SSH client |

## Support the project

Phalanx is built in the open. If the work here is useful to you, the most direct way to help is to **star the repo** -- it makes the project easier for other PHP developers to find, and it keeps the work visible. Issues, discussion, and pull requests are welcome.

## License

MIT. See [Contributing](https://github.com/phalanx-php/phalanx-aegis/blob/main/CONTRIBUTING.md) to get involved.
