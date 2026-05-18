<p align="center">
  <img src="logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx

**Async PHP that feels like normal code — protected by a runtime that actually has your back.**

Managed concurrency, surfaced as plain PHP. Underneath, a small kernel tracks every resource in a ledger and nests scopes inside scopes — Russian-doll style — so cleanup and control propagate through every layer.

If this resonates, **a star helps us gauge whether to keep pushing**. We're early; signal matters.

### A working HTTP app, top to bottom

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload_runtime.php';

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Stoa;
use Phalanx\Task\Scopeable;

class UserRepo
{
    private array $users = [
        1 => ['id' => 1, 'name' => 'Ada Lovelace'],
        2 => ['id' => 2, 'name' => 'Alan Turing'],
    ];

    public function find(int $id): ?array
    {
        return $this->users[$id] ?? null;
    }
}

class AppBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(UserRepo::class)->factory(static fn() => new UserRepo());
    }
}

class ShowUser implements Scopeable
{
    public function __invoke(RequestScope $scope): array
    {
        $id = (int) $scope->params->get('id');
        $user = $scope->service(UserRepo::class)->find($id);

        return ['user' => $user];
    }
}

return static function (array $context): \Closure {
    $app = Stoa::starting($context)
        ->bundles(new AppBundle())
        ->routes(RouteGroup::of([
            'GET /users/{id:int}' => ShowUser::class,
        ]))
        ->build();

    return static fn (): int => $app->run();
};
```

That's the whole thing — entry, controllers, routing, a service lookup. The handlers are plain invokable classes. The `RequestScope` they receive is the protected execution context: it knows the route params, the request, the active services, and what to clean up. No `await`. No fiber bookkeeping. It reads like synchronous PHP.

The same contract powers CLI commands, WebSocket handlers, background workers, and streaming agents — one consistent surface across transports.

### The CYA system

Every unit of work in Phalanx executes inside an owned `Scope`. This is the protective layer that makes long-running PHP safe for normal developers:

- **Task ownership & supervision** — Every job becomes a traceable `TaskRun`
- **Automatic cancellation** — Disconnects, timeouts, explicit cancels all propagate correctly
- **Guaranteed disposal** — `onDispose()` hooks always fire, even on abrupt shutdown
- **Wait diagnostics** — Know exactly why a coroutine is suspended and for how long
- **Service lifecycle** — Singletons and scoped services with clean startup/shutdown
- **Worker & boundary safety** — Closures and state never leak across process lines

You don't manage fibers or pools. You use the narrowest scope interface you need and let Aegis handle the rest.

### Built on OpenSwoole 26

Phalanx runs on OpenSwoole 26 — native PHP fibers, `io_uring`, `Channel`, `WaitGroup`, `ClientPool`. Early results are very promising: the runtime kernel benchmarks are healthy, the boot harness catches misconfiguration before workers spin up, and the test surface is stabilizing fast. If you have an OpenSwoole environment lying around, **try it out** — feedback on real workloads is exactly what we need right now.

### Demos

Real, runnable examples covering the core surface. Each ships with a `.env.example` and runs via `php demo.php`:

- [Aegis kernel](demos/aegis) — runtime policy, scope supervision, cancellation, singleflight, runtime memory
- [Stoa HTTP](demos/stoa) — basic routing, JSON API, realtime SSE, runtime lifecycle
- [Archon CLI](demos/archon) — basic commands, interactive input, supervised concurrency, runtime lifecycle
- [Athena AI](demos/athena) — concurrent streaming across providers, Guzzle SDK coexistence
- [Surreal](demos/surreal) — in-memory RPC, live queries

### Benchmarks

The managed runtime tax is about 4%. Every unit of work flows through the full Aegis kernel -- scope creation, supervisor registration, cancellation propagation, disposal -- and the cost is negligible against raw OpenSwoole.

> PHP 8.4.16, OpenSwoole 26.2.0, Apple M-series.
> Run `composer bench:aegis` and `composer bench:stoa` to reproduce.

<details>
<summary><strong>Context switching</strong> -- 1M managed switches vs raw OpenSwoole vs raw Fibers</summary>

1,000 units x 1,000 suspends per iteration. The Fiber number is the theoretical floor (no Phalanx code path can reach it -- all suspension routes through OpenSwoole's reactor). The number that matters is managed vs raw Swoole.

| Tier | Mean (us) | P95 (us) | Ops/sec | Note |
|------|-----------|----------|---------|------|
| PHP Fiber | 150,651 | 170,983 | 6.64 | raw PHP baseline |
| OpenSwoole Coroutine | 574,041 | 587,353 | 1.74 | OpenSwoole scheduler only |
| Phalanx Managed | 596,375 | 612,068 | 1.68 | full scope + supervisor overhead |

</details>

<details>
<summary><strong>Aegis kernel</strong> -- scope, task, supervision, cancellation primitives</summary>

Isolates the cost of individual Aegis operations. `ObjectPool` + `resetAsLazyGhost()` recycles TaskRun and scope frame objects to avoid ZMM fragmentation from alloc/free churn.

| Case | Mean (us) | P95 (us) | Ops/sec | GC roots |
|------|-----------|----------|---------|----------|
| scope_create_dispose | 3.23 | 3.46 | 309,491 | 15 |
| execute_noop_task | 2.78 | 3.42 | 360,262 | 19 |
| execute_noop_task_unpooled | 2.81 | 3.46 | 355,702 | 18 |
| execute_static_task_of | 3.15 | 3.79 | 317,034 | 17 |
| supervisor_lifecycle | 2.58 | 3.17 | 387,326 | 19 |
| trace_log_churn | 0.28 | 0.33 | 3,525,230 | 10,007 |
| concurrent_noop_100 | 1,054 | 1,112 | 949 | 324 |
| concurrent_noop_unpooled_100 | 1,069 | 1,179 | 935 | 222 |
| concurrent_delay_100 | 3,398 | 3,919 | 294 | 529 |
| singleflight_waiters_100 | 3,661 | 4,063 | 273 | 337 |
| cancel_sleeping_children_100 | 4,286 | 8,251 | 233 | 934 |
| ledger_inprocess_lifecycle | 2.59 | 3.17 | 386,817 | 19 |
| ledger_swoole_table_lifecycle | 117.38 | 131.71 | 8,519 | 126 |
| ledger_swoole_table_projection | 185.89 | 205.33 | 5,380 | 125 |
| transaction_scope_enter_exit | 3.72 | 3.92 | 268,554 | 19 |

</details>

<details>
<summary><strong>Stoa HTTP dispatch</strong> -- internal per-request overhead (no network)</summary>

Measures Stoa's request lifecycle in isolation -- request factory, routing, handler dispatch, scope cleanup. No TCP/TLS involved.

| Case | Mean (us) | P95 (us) | Ops/sec |
|------|-----------|----------|---------|
| stoa_dispatch_plaintext | 434 | 475 | 2,306 |
| stoa_dispatch_json | 424 | 469 | 2,360 |
| stoa_dispatch_route_param | 428 | 474 | 2,337 |
| stoa_request_factory | 5.10 | 6.29 | 195,977 |
| stoa_request_resource_lifecycle | 421 | 466 | 2,373 |
| stoa_drain_cleanup | 17,319 | 17,721 | 58 |
| stoa_dispatch_dto_unused | 458 | 508 | 2,184 |
| stoa_dispatch_dto_used | 459 | 516 | 2,176 |

</details>

---

Monorepo with automatic read-only package splits — all development happens here.
