<p align="center">
  <img src="assets/banner.svg" alt="Phalanx" width="520">
</p>

# Phalanx

Phalanx is a supervised execution framework for PHP 8.4+ applications built on OpenSwoole.

That is the short version. The longer version is the reason I am still building it.

PHP has plenty of frameworks. Plenty of async libraries too. Phalanx is not trying to be a nicer wrapper around one more event loop. It uses a different mental model: make work explicit, give that work an owner, then let the runtime track what happens from there.

The name is intentional: Phalanx is about disciplined units of work moving together, each one visible, owned, and accountable.

Requests. Commands. Terminal UI frames. WebSocket sessions. Worker jobs. Network probes. AI agent turns. Same idea underneath.

Phalanx is pre-alpha. The pieces are moving quickly, but the core shape is already visible: one framework, multiple modules, one runtime model.

> Look useful? Consider starring the repo
>
> Stay in the loop on X: [![Follow @j_havenz on X](https://img.shields.io/badge/Follow%20%40j_havenz-000000?logo=x&logoColor=white)](https://x.com/j_havenz)

## The Mental Model

Phalanx starts from a small premise:

> Every meaningful unit of work should have an owner.

That is the phalanx mindset. Individual pieces still matter, but the system only works when participation is explicit: tasks declare what they are, scopes own what they start, and the runtime keeps the formation visible.

That owner is a scope. A scope owns the work it starts. When the scope ends, Phalanx has a place to cancel child work, dispose managed resources, close services, record diagnostics, and show what was waiting on what.

The public API leans into invokable objects:

- constructor arguments describe the input to the computation
- `__invoke(...)` performs the coordination
- interfaces describe runtime signals like timeouts, tracing, cleanup, or supervision
- the scope carries the runtime affordances for the thing currently executing

That gives Phalanx an "OO, but functional enough" shape. Objects carry dependencies and intent. Invocations run like explicit computations. The runtime can see them.

```php
<?php

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

class ResizeAvatar implements Executable
{
    public function __construct(
        private UserId $userId,
        private UploadedFile $file,
    ) {}

    public function __invoke(ExecutionScope $scope): Avatar
    {
        $images = $scope->service(ImagePipeline::class);

        return $images->resizeAvatar($this->userId, $this->file);
    }
}
```

The class is still just PHP. The difference is that Phalanx can supervise the work because the work has a visible boundary.

## The Footgun It Catches

Async PHP makes some problems look ordinary until they are not. Lock-order inversion is one of those. Each task below can be reasonable in isolation, but together they can park forever if a developer misses the cross-task lock order.

```php
<?php

// Imports, properties, some methods, and constructors omitted for brevity.

class MoveCustomerCredit implements Executable, Retryable, HasTimeout
{
    public function __invoke(ExecutionScope $scope): void
    {
        $scope->service(BillingLedger::class)->customerThenInvoice($this->customerId, $this->invoiceId);
    }
}

class MoveInvoiceCredit implements Executable, Retryable, HasTimeout
{
    public function __invoke(ExecutionScope $scope): void
    {
        $scope->service(BillingLedger::class)->invoiceThenCustomer($this->invoiceId, $this->customerId);
    }
}

class ReconcileBilling implements Executable, Retryable, HasTimeout
{
    public function __invoke(ExecutionScope $scope): void
    {
        $scope->concurrent(
            new MoveCustomerCredit($this->customerId, $this->invoiceId),
            new MoveInvoiceCredit($this->invoiceId, $this->customerId),
        );
    }
}
```

This is the kind of thing even vigilant async PHP programmers can miss. The code is local. The bug is relational.

Phalanx does not pretend PHP can guarantee safety. It gives the runtime enough structure to help. Aegis has supervised leases, wait reasons, and `DiagnosticCode::LockOrderViolation` so lock intent can become runtime-visible instead of living only in somebody's head.

If the business operation is ordered, say that. `series()` keeps the named tasks separate, runs them one at a time, and returns the ordered results.

```php
<?php

// ...

class ReconcileBillingInOrder implements Executable, Retryable, HasTimeout
{
    public function __invoke(ExecutionScope $scope): void
    {
        $scope->series(
            move: new MoveCustomerCredit($this->customerId, $this->invoiceId),
            refund: new MoveInvoiceCredit($this->invoiceId, $this->customerId),
        );
    }
}
```

If the operations really are concurrent, keep them concurrent, but move contested resources behind one canonical ledger path.

```php
<?php

// ...

class MoveCustomerCredit implements Executable, Retryable, HasTimeout
{
    public function __invoke(ExecutionScope $scope): void
    {
        $scope
            ->service(BillingLedger::class)
            ->withCreditPair($this->customerId, $this->invoiceId, static function (BillingLedger $ledger): void {
                $ledger->moveCustomerCredit();
            });
    }
}

class MoveInvoiceCredit implements Executable, Retryable, HasTimeout
{
    public function __invoke(ExecutionScope $scope): void
    {
        $scope
            ->service(BillingLedger::class)
            ->withCreditPair($this->customerId, $this->invoiceId, static function (BillingLedger $ledger): void {
                $ledger->moveInvoiceCredit();
            });
    }
}
```

Same work. One resource order. Something the runtime, and the person reading the code, can reason about.

> The DX tradeoff is deliberate: this is more explicit than a promise chain, but the payoff is named work with scheduling semantics, lifecycle, timeout/retry policy, and runtime diagnostics attached.

## One Bootstrap Shape

Each app surface has a small facade builder. The bootstrap describes the app directly, then calls `run()`.

HTTP:

```php
<?php

use Phalanx\Stoa\Stoa;

return static fn(array $context): \Closure => static fn(): int => Stoa::starting($context)
    ->routes(__DIR__ . '/routes.php')
    ->run();
```

CLI:

```php
<?php

use Phalanx\Archon\Application\Archon;

return static fn(array $context): \Closure => static fn(): int => Archon::starting($context)
    ->commands($commands)
    ->run();
```

TUI:

```php
<?php

use App\TemplateApp;
use Phalanx\Iris\HttpServiceBundle;
use Phalanx\Theatron\Agent\AthenaServiceBundle;
use Phalanx\Theatron\Theatron;
use Phalanx\Theatron\TheatronApp;
use Phalanx\Theatron\TheatronServiceBundle;

return static fn(array $context): \Closure => static fn(): int => Theatron::app($context)
    ->store(TemplateApp::store())
    ->screens(TemplateApp::screens())
    ->globalBindings(TemplateApp::bindings())
    ->providers(
        static fn(TheatronApp $app): TheatronServiceBundle => new TheatronServiceBundle($app),
        new HttpServiceBundle(),
        AthenaServiceBundle::ollama(),
    )
    ->devtools()
    ->run();
```

Different surface. Same posture: describe the app, register the providers, run it.

That consistency is intentional. Phalanx trades a little DX magic for explicitness at the boundary where it matters most. The bootstrap becomes a readable inventory of what the app is.

## Scope And Context

Phalanx uses both words on purpose.

A **scope** owns lifetime: services, child work, cancellation, cleanup, runtime memory, diagnostics.

A **context** carries the pointed data for what is happening right now: an HTTP request, a CLI command, a TUI render pass, a WebSocket message, an agent turn.

The context lives inside the scope.

That distinction keeps the runtime honest without making every handler think about the whole runtime.

## What You Can Build

Phalanx is not only for web apps.

- HTTP APIs, SSE streams, request diagnostics, middleware, and route dispatch
- CLI tools, interactive commands, supervised console flows, and process lifecycles
- terminal UIs with screens, stores, input bindings, settings, devtools, and model request inspection
- WebSocket servers and clients
- worker processes and structured parallelism
- UDP and network tooling
- reverse proxies, load balancers, and operational sidecars
- devops scripts that need cancellation, supervision, retries, and runtime visibility
- AI harnesses that need provider adapters, tool calls, grants, effects, and MCP

The point is momentum. PHP teams already know PHP. Phalanx opens more of the systems-programming edge without forcing every project into a different language, runtime, and debugging story.

## Framework Modules

The modules are moving into one cohesive framework. The names are still useful when reading the code:

| Module | Runtime surface |
| --- | --- |
| Aegis | managed execution, scopes, supervision, leases, runtime memory |
| Stoa | HTTP server, routing, middleware, SSE, request lifecycle |
| Archon | CLI applications, commands, arguments, interactive input |
| Theatron | terminal UI screens, stores, bindings, devtools, request inspection |
| Hydra | worker processes and structured parallelism |
| Hermes | WebSocket server and client work |
| Athena | supervised AI turns, effects, grants, tools, MCP |
| Panoply | provider-neutral AI surface and adapters |
| Iris | outbound HTTP |
| Grammata | filesystem work |
| Enigma | SSH and tunnels |
| Surreal | SurrealDB RPC and live queries |
| Skopos | dev server orchestration |
| Eidolon | frontend bridge contracts |
| PHPStan rules | static safety checks for runtime-sensitive patterns |

These are not separate mental models. They are entry points into the same supervised execution model.

## Demos

The repo includes runnable demos for the current surfaces. Development is moving quickly, so demos may change or go stale while the alpha shape settles.

| Demo | Covers | Command |
| --- | --- | --- |
| [Aegis kernel](demos/aegis) | runtime policy, scope supervision, cancellation, singleflight, runtime memory | `composer demo:aegis` |
| [Stoa HTTP](demos/stoa) | routing, JSON APIs, realtime SSE, runtime lifecycle, diagnostics | `composer demo:stoa` |
| [Archon CLI](demos/archon) | commands, interactive input, supervised concurrency, lifecycle, diagnostics | `composer demo:archon` |
| [Hydra workers](demos/hydra) | workers, structured parallelism, cancellation behavior | `composer demo:hydra` |
| [Athena AI](demos/athena) | streaming providers, SDK coexistence, MCP stdio/SSE, support triage, research agents | `composer demo:athena` |
| [Surreal](demos/surreal) | in-memory RPC and live queries | `composer demo:surreal` |
| [Skopos](demos/skopos) | basic dev server orchestration | `composer demo:skopos:basic-dev` |

## Benchmarks

The managed runtime tax is about 4%. Every unit of work flows through the full Aegis kernel: scope creation, supervisor registration, cancellation propagation, disposal, and ledger updates. The current numbers keep that cost close to raw OpenSwoole.

> PHP 8.4.16, OpenSwoole 26.2.0, Apple M-series.
> Run `composer bench:aegis` and `composer bench:stoa` to reproduce.

<details>
<summary><strong>Context switching</strong> - 1M managed switches vs raw OpenSwoole vs raw Fibers</summary>

1,000 units x 1,000 suspends per iteration. The Fiber number is the theoretical floor. No Phalanx code path can reach it because all suspension routes through OpenSwoole's reactor. The number that matters is managed vs raw Swoole.

| Tier | Mean (us) | P95 (us) | Ops/sec | Note |
|------|-----------|----------|---------|------|
| PHP Fiber | 150,651 | 170,983 | 6.64 | raw PHP baseline |
| OpenSwoole Coroutine | 574,041 | 587,353 | 1.74 | OpenSwoole scheduler only |
| Phalanx Managed | 596,375 | 612,068 | 1.68 | full scope + supervisor overhead |

</details>

<details>
<summary><strong>Aegis kernel</strong> - scope, task, supervision, cancellation primitives</summary>

Isolates the cost of individual Aegis operations. `ObjectPool` and `resetAsLazyGhost()` recycle TaskRun and scope frame objects to avoid ZMM fragmentation from alloc/free churn.

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
<summary><strong>Stoa HTTP dispatch</strong> - internal per-request overhead (no network)</summary>

Measures Stoa's request lifecycle in isolation: request factory, routing, handler dispatch, scope cleanup. No TCP/TLS involved.

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

## Status

Phalanx is pre-alpha. I am aiming to have the first alpha release ready soon.

The current direction:

- one repo
- one framework
- modules instead of scattered libraries
- one supervised execution model across HTTP, CLI, TUI, workers, sockets, network tools, and AI surfaces

The details will keep tightening. That is where the project is right now.
