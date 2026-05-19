<p align="center">
  <img src="logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx

**A supervised execution runtime for PHP 8.4+.**

Phalanx is an altogether unique take on the potential of what PHP8.4+ can become, given the firepower available to it in 2026. [PIE](https://github.com/php/pie) is finding its footing right now and I'm seeing more and more people getting involved/releasing [extensions](https://packagist.org/extensions). This coupled with features added from PHP 8+, both syntactically and [under the hood](https://www.php.net/manual/en/language.oop5.lazy-objects.php) (for lazy init and obj/mem pooling), have paved the way for something with powerful potential. Phalanx PHP is my personal take on bringing all these factors together and in a way that strikes just the right balance between explicitness and a great DX.

<expand>
    <summary>Quick backstory</summary>
    This idea has evolved since I started working on it in late 2024. Seeing so many anonymous closures and deadlocks plaguing the async PHP world, I'd originally built an IP scanner over UDP. I'd also recently finished this [.NET Book on DI](https://www.ebay.com/itm/366054262373) and found the impact that scopes can have to be much more profound then anything were currently seeing the PHP ecosystem.
</expand>

## Whats Phalanx?

PHP's model is fire-and-forget. Request comes in, runs, process dies. That's fine until you want the process to stay alive -- and then every assumption breaks. State leaks, closures capture object graphs, timers outlive their owners, and background work drifts untethered. The async PHP ecosystem has answers for pieces of this, but nothing that owns the whole problem.

That's what I'm trying to build. The operative word is *trying*.

The idea is a runtime where every unit of work -- HTTP request, CLI command, WebSocket session, AI agent turn -- flows through one supervised execution path. The core primitive is the **scope**: a hierarchy where parent owns children, and ownership means cleanup, cancellation, and resource disposal all propagate automatically. In theory, no orphaned fibers, no leaked connections, no silent background work outliving its owner. Early kernel benchmarks are encouraging here -- the managed runtime overhead has been hovering in the low single digits over raw OpenSwoole, and scope creation, supervisor registration, cancellation propagation, and disposal are all cheap. Those numbers will move as the surface grows, but the trend line is healthy.

All concurrency primitives -- `concurrent()`, `race()`, `map()`, `retry()`, `timeout()`, `inWorker()` -- route through a single centralized supervisor. The supervisor ledger tracks every TaskRun: what's executing, what's waiting, why it's waiting, and who owns it. Whether work is concurrent (fiber interleaving in one process) or parallel (child processes), one scheduler, one execution contract. That part is working and tested -- the HTTP dispatch path, CLI runner, and WebSocket lifecycle all flow through it today, though the edges are still being found.

Memory management is where long-running PHP gets truly unforgiving -- one surviving reference in a 2MB ZMM chunk pins the whole thing. Phalanx addresses this with object pooling via `resetAsLazyGhost()`, recycling slots across thousands of iterations without deallocation churn. PHPStan rules catch the worst footguns (non-static closures, escaped borrowed values, raw OpenSwoole calls outside the framework) at compile time. Allocation pressure in benchmarks has stayed flat so far, and the static analysis surface is tightening with each round -- but "so far" is doing a lot of work in that sentence. Real workloads will find what synthetic ones missed.

I haven't run this under real traffic with real failure modes and real operator pressure. That's an entirely different bar, and I'm aware of the distance between where this is and where I want it to be. The supervision only works if you work with it -- follow the scope conventions, use the tooling, let PHPStan yell at you. Do that, and there's serious firepower under the hood.

PHP has always been a language for getting things done -- you hit a problem, you write some PHP, and it works. The tools available to it now are more powerful than most people realize, and they haven't come together yet in a way that brings all that firepower to bear for everyday developers. Concurrency, streaming, AI agents, realtime protocols -- these shouldn't require a different stack. Phalanx is my attempt to make PHP the language you'd *want* to default to when these demands come up, where even a newcomer looks at the problem and thinks: yeah, I can do that -- give me a couple days.

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

That's the whole thing -- entry point, service registration, routing, handler. `ShowUser` is a plain invokable class that receives a `RequestScope`. The scope knows the route params, the request, the active services, and what to clean up when the request ends. No `await`. No fiber bookkeeping. It reads like synchronous PHP.

> [!NOTE]
> Most of what you see on `RequestScope` -- and indeed, throughout Phalanx's public APIs -- doesn't cost anything until you use it. The request body isn't parsed until you call `$scope->body->json()`. Service resolution on `$scope->ctx` is deferred until first access. Input DTOs are hydrated as lazy ghosts -- the object exists, passes `instanceof`, but its properties aren't populated until you read one. The expensive work only happens if you ask for it.

### The same contract, different transport

The CLI runner looks almost identical. Here's a command that fetches two things concurrently:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Executable;

class FetchReport implements Executable
{
    public function __invoke(CommandScope $scope): int
    {
        [$users, $metrics] = $scope->concurrent(
            new FetchUsers(),
            new FetchMetrics(),
        );

        $scope->service(StreamOutput::class)->persist(
            sprintf('%d users, %d metrics', count($users), count($metrics)),
        );

        return 0;
    }
}

return static function (array $context): \Closure {
    $app = Archon::starting($context)
        ->commands(CommandGroup::of([
            'report' => [FetchReport::class, new CommandConfig(
                description: 'Fetch users and metrics concurrently.',
            )],
        ]))
        ->build();

    return static fn (): int => $app->run();
};
```

> [!NOTE]
> Every Phalanx entry point goes through [symfony/runtime](https://symfony.com/doc/current/components/runtime.html). At boot, it collects `$_SERVER` and `$_ENV` into one `$context` array, then hands it to your closure. From that point on, nothing in the framework touches superglobals. Config flows through `AppContext` -- typed accessors, missing-key exceptions, no silent nulls. Environment values reach your services through the container, not through `getenv()` or `$_SERVER`.

The difference between `Scopeable` and `Executable` is what the handler needs. `Scopeable` gets service resolution and cancellation -- enough for most handlers. `Executable` adds the concurrency primitives: `concurrent()`, `race()`, `map()`, `retry()`, `timeout()`, `inWorker()`. Same scope hierarchy either way. Same supervisor underneath. Whether the work runs over HTTP, CLI, WebSocket, or a background job, it flows through the same execution path.

### What's under the hood

Every unit of work runs inside an owned scope, and every scope registers with a centralized supervisor. In practice, that means:

- **Task identity** -- every job becomes a tracked `TaskRun` with a name, owner, and lifecycle. If something is running, you can see it.
- **Cancellation propagation** -- disconnects, timeouts, and explicit cancels flow down the scope tree. A cancelled parent cancels its children. This has been one of the more reliable parts of the system in testing.
- **Disposal** -- `onDispose()` hooks fire when a scope ends, even on abrupt shutdown. Resources registered with a scope get cleaned up with that scope.
- **Wait diagnostics** -- the supervisor tracks why each coroutine is suspended and for how long. Mostly an operator tool, but it's already caught real issues during development.
- **Service lifecycle** -- singletons live for the application; scoped services live for the request or command. Startup and shutdown are managed, not ad-hoc.
- **Worker boundaries** -- closures and unserializable state don't cross process lines. PHPStan catches this at compile time; the runtime catches it if PHPStan didn't.

None of this requires the application developer to think about fibers or event loops. You pick the scope interface that fits, and the runtime handles the rest. That's the idea -- and it's holding up in testing so far.

### Built on OpenSwoole 26

Phalanx runs on OpenSwoole 26 -- native PHP fibers, `io_uring`, `Channel`, `WaitGroup`, `ClientPool`. The kernel benchmarks are healthy, the boot harness catches misconfiguration before workers spin up, and the test surface is stabilizing. You're welcome to start kicking the tires -- you can clone the code and run/review the

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
