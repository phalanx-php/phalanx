<p align="center">
  <img src="assets/banner.svg" alt="Phalanx" width="520">
</p>

# Phalanx

**A supervised execution runtime for PHP 8.4+, built on OpenSwoole with the following features:**

- **A ledger-based kernel for tracking, tracing, debugging, reaping, and spawning sync, concurrent, and/or parallel tasks**

- **Object pooling for memory management and performant ([...]?) allocations.

- **Consistent and conventional userland APIs that leverage the `__invoke` method to view objects as more of a functional construct (aka `Executable`) that can possess the following qualities:**

    - Implement interfaces
    - Use interfaces as a way to provide cooperative signals and metadata to the Phalanx kernel
    - The constructor becomes the means for providing 'function arguments' to the computation, not the `__invoke` function itself
    - Use the `__invoke(ExecutionScope $scope, ...)` signature for cooperative task scheduling, 

        e.g.

        This example shows a [lock-order inversion](packages/phalanx-aegis/src/Supervisor/LockLease.php) even vigilant async PHP programmers can miss: each task is reasonable alone, but together they can park forever unless the developer notices the cross-task lock order and handles it manually.

        ```php
        <?php

        // note: imports, properties, and ctors omitted for brevity...

        final class ApplyCustomerCredit implements Executable, HasTimeout, Traceable
        {
            public function __invoke(ExecutionScope $scope): void
            {
                $scope
                    ->service(BillingLedger::class)
                    ->moveCustomerCreditToInvoice($this->customerId, $this->invoiceId);
            }
        }

        final class RefundInvoiceCredit implements Executable, HasTimeout, Traceable
        {
            public function __invoke(ExecutionScope $scope): void
            {
                $scope
                    ->service(BillingLedger::class)
                    ->moveInvoiceCreditToCustomer($this->invoiceId, $this->customerId);
            }
        }

        final class ReconcileBilling implements Executable, Traceable
        {
            public function __invoke(ExecutionScope $scope): void
            {
                $scope->concurrent(
                    new ApplyCustomerCredit($this->customerId, $this->invoiceId),
                    new RefundInvoiceCredit($this->invoiceId, $this->customerId),
                );
            }
        }
        ```

        In Phalanx, the fix is to make the lock intent visible to the runtime. The two business operations stay separate, but both acquire their resources through a Phalanx-aware ledger path so canonical ordering and `LockOrderViolation` diagnostics can do their job.

        ```php
        <?php

        // note: imports, properties, and ctors omitted for brevity...

        final class ApplyCustomerCredit implements Executable, HasTimeout, Traceable
        {
            public function __invoke(ExecutionScope $scope): void
            {
                $scope
                    ->service(BillingLedger::class)
                    ->moveCustomerCreditToInvoiceSafely($this->customerId, $this->invoiceId);
            }
        }

        final class RefundInvoiceCredit implements Executable, HasTimeout, Traceable
        {
            public function __invoke(ExecutionScope $scope): void
            {
                $scope
                    ->service(BillingLedger::class)
                    ->moveInvoiceCreditToCustomerSafely($this->invoiceId, $this->customerId);
            }
        }

        final class ReconcileBilling implements Executable, Traceable
        {
            public function __invoke(ExecutionScope $scope): void
            {
                $scope->concurrent(
                    new ApplyCustomerCredit($this->customerId, $this->invoiceId),
                    new RefundInvoiceCredit($this->invoiceId, $this->customerId),
                );
            }
        }
        ```

Phalanx is a framework built on OpenSwoole and it's organized around two primary ideas:

- _Every unit of work (a 'scope') has an owner and a lifecycle which can't outlive it's parent._

- _Every userland api or design requires a conscious trade-off


That applies whether the work is an HTTP request, a CLI command, a terminal UI frame, a WebSocket session, a worker job, an outbound request, a filesystem operation, a database stream, or an AI agent turn.

> Look useful? Consider starring the repo
>
> Stay in the loop on X: [![Follow @j_havenz on X](https://img.shields.io/badge/Follow%20%40j_havenz-000000?logo=x&logoColor=white)](https://x.com/j_havenz)

## What Phalanx Is

Phalanx is a full-stack runtime framework for long-lived PHP applications. It gives each application surface a focused builder, then routes the resulting work through the same managed runtime underneath.

- **Stoa** for HTTP servers, routing, middleware, SSE, and request diagnostics.
- **Archon** for CLI applications, commands, arguments, interactive input, and console lifecycles.
- **Theatron** for terminal UIs, screens, state stores, input bindings, and devtools.
- **Hermes** for WebSocket server and client work.
- **Hydra** for structured worker-process parallelism.
- **Athena** and **Panoply** for supervised AI turns, effects, grants, tool calls, provider adapters, and MCP.
- **Iris**, **Grammata**, **Enigma**, and **Surreal** for outbound HTTP, filesystem work, SSH/tunnels, and SurrealDB RPC/live queries.
- **Skopos**, **Eidolon**, and the static-analysis rules for dev orchestration, frontend contracts, and runtime safety checks.

The names are useful when you are reading the code. The important part is that they are all modules of one framework, not separate mental models.

## One Runtime Shape

HTTP:

```php
use Phalanx\Stoa\Stoa;

return static fn(array $context): \Closure => static fn(): int => Stoa::starting($context)
    ->routes(__DIR__ . '/routes.php')
    ->run();
```

CLI:

```php
use Phalanx\Archon\Application\Archon;

return static fn(array $context): \Closure => static fn(): int => Archon::starting($context)
    ->commands($commands)
    ->run();
```

TUI:

```php
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

The transport changes. The ownership model does not.

Every request, command, socket, worker task, and agent turn runs inside a scope. A scope owns the work it starts. When the scope ends, Phalanx knows what to cancel, what to dispose, which services are still alive, and which task was waiting on what.

## How It Works

**Builders describe the app.**
Each surface starts from a focused builder: `Stoa::starting(...)`, `Archon::starting(...)`, `Theatron::app(...)`. The bootstrap stays explicit: routes, commands, screens, providers, devtools, runtime settings.

**Scopes own the work.**
Handlers receive scoped access to services, cancellation, lifecycle hooks, runtime memory, and concurrency primitives. Work started inside a scope belongs to that scope.

**The supervisor tracks execution.**
Every managed task has an identity, parent, lifecycle, wait reason, and disposal path. That gives Phalanx a shared vocabulary for cancellation, diagnostics, devtools, and cleanup.

**Modules share the runtime.**
HTTP, CLI, TUI, workers, WebSockets, agents, files, network calls, and database streams are different entry points into the same execution model.

**Static analysis backs up runtime rules.**
The framework includes PHPStan rules for the patterns that need to stay disciplined in concurrent and parallel PHP.

## Quick Start

```bash
composer install
```

Run a demo:

```bash
composer demo:stoa
```

Run the full verification suite:

```bash
composer check
```

Useful focused demos:

```bash
composer demo:aegis
composer demo:archon
composer demo:hydra
composer demo:athena
composer demo:surreal
```

## Demos

The repo includes runnable examples for the current runtime surfaces:

| Demo | Covers |
| --- | --- |
| `demos/aegis` | Runtime policy, scope supervision, cancellation, singleflight, runtime memory |
| `demos/stoa` | Routing, JSON APIs, realtime SSE, runtime lifecycle, diagnostics |
| `demos/archon` | Commands, interactive input, supervised concurrency, lifecycle, diagnostics |
| `demos/hydra` | Basic workers, structured parallelism, cancellation behavior |
| `demos/athena` | Streaming providers, third-party SDK coexistence, MCP stdio/SSE, support triage, research agents |
| `demos/surreal` | In-memory RPC and live queries |
| `demos/skopos` | Basic dev server orchestration |

## Performance

Phalanx routes managed work through the same kernel path: scope creation, supervisor registration, cancellation propagation, disposal, and ledger updates. The benchmark suite measures that path against raw OpenSwoole and lower-level PHP baselines.

Run it locally:

```bash
composer bench:aegis
composer bench:stoa
```

Benchmark details live in [benchmarks](benchmarks).

## Status

Phalanx is early and active. The core runtime, HTTP path, CLI path, TUI path, worker path, WebSocket pieces, AI runtime, SurrealDB client, demos, static rules, and verification scripts are moving in this monorepo.

The project is still settling into its final framework shape. The direction is simple: one repo, one framework, many modules, one supervised execution model.

## Repository Map

| Path | Purpose |
| --- | --- |
| `packages/` | Framework modules maintained from this monorepo |
| `demos/` | Runnable examples for each runtime surface |
| `benchmarks/` | Kernel and HTTP benchmark harnesses/results |
| `docs/` | Internal references and deeper notes |
| `tests/` | Monorepo-level tests |

## Development

```bash
composer test
composer analyse
composer cs
composer fmt:dry
composer check
```

`composer check` is the high-signal command: PHPStan, Rector dry run, formatting dry run, PHPCS, generated accessor sync, PHPUnit, and demo linting.

---

Monorepo with automatic read-only package splits. All development happens here.
