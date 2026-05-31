<p align="center">
  <img src="assets/banner.svg" alt="Phalanx" width="520">
</p>

# Phalanx

Phalanx is a supervised execution framework for PHP 8.4+ applications built on Swoole.

PHP has plenty of frameworks. Plenty of async libraries too. Phalanx is for the part they tend to leave implicit: the footguns that appear when correct-looking async PHP is composed, retried, deferred, streamed, pooled, or left running long enough to matter.

The name is intentional: Phalanx is about disciplined units of work moving in deliberate unison, stronger as one formation than as scattered parts.

Requests. Commands. TUIs. WebSockets. Workers. Networks. Agent turns. The same general shape threads through them all.

> Look useful? Consider starring the repo
>
> Stay in the loop on X: [![Follow @j_havenz on X](https://img.shields.io/badge/Follow%20%40j_havenz-000000?logo=x&logoColor=white)](https://x.com/j_havenz)

## The Mental Model

Phalanx starts from a small premise:

> Every meaningful unit of work should have an owner.


_We'll get right into some code:_

```php
<?php
class CreateProject implements Executable
{
    // ctor omitted for brevity -- you'll see this throughout this README

    public function __invoke(RequestContext $ctx): Project
    {
        [$project] = $ctx->scope->series(
            create: new InsertProject($this->input),
            audit: new WriteAuditEntry('project.created'),
            cache: new RefreshProjectList($this->input->ownerId),
        );

        return $project;
    }
}
```

The insert, audit write, and cache refresh are separate actions, but they are not scattered side effects. They are explicit tasks executed within the scope. This guarantees they share the same timeouts, cancellation signals, and cleanup lifecycle.

This is the Phalanx mindset: work must be supervised. Scopes own execution. When a scope ends, the runtime knows exactly what to cancel, which resources to dispose, and what services to close. Child (or nested) scopes get cleaned up in first.

The public API relies on invokable objects:
- Constructors capture input arguments.
- `__invoke(...)` provides the coordination.
- Interfaces signal runtime behavior (timeouts, retries, cleanup) to the kernel.
- The scope provides execution boundaries and service resolution.

Objects carry intent. Invocations act as explicit computations. The runtime monitors it all. For this reason, you'll find very few _anonymous_ closures. Though, they're still available to use where it makes sense to break convention.

## Identity Over Anonymity

Phalanx separates what a computation needs from how it runs. Constructors hold runtime arguments; the scope typically resolves services. DI is available, when it makes sense.

```php
<?php

class ResizeImage implements Executable
{
    public function __construct(
        private ImageId $id,
        private int $width,
    ) {}

    public function __invoke(ExecutionScope $scope): Image
    {
        $pipeline = $scope->service(ImagePipeline::class);

        return $pipeline->resize($this->id, $this->width);
    }
}
```

By using invokable classes instead of anonymous closures, business logic gains identity. It becomes inspectable, serializable, and easy to test in isolation.

## Structured Safety

Phalanx provides a safety net for relational bugs that often go unnoticed in async development. Take lock-order inversion as an example:

```php
<?php

/**
 * If `ChargeUser` and `LogRefund` attempt to acquire the same resources (e.g., the user account and the transaction log)
 * but in opposite orders, they can deadlock waiting for each other.
 */
class ProcessRefund implements Executable
{
    public function __invoke(ExecutionScope $scope): void
    {
        $scope->concurrent(
            new ChargeUser($this->userId, $this->amount),
            new LogRefund($this->refundId),
        );
    }
}
```

When a concurrency hazard occurs, Phalanx emits structured errors like `[PHX-LOCK-001]`. This is part of a diagnostic system that labels failures with an exact code, making it traceable through logs and TUI devtools.

If an operation requires strict ordering, use the `series` primitive. It keeps the tasks separate and manageable while ensuring they execute one at a time.

```php
<?php

class ProcessRefundInOrder implements Executable
{
    public function __invoke(ExecutionScope $scope): void
    {
        $scope->series(
            charge: new ChargeUser($this->userId, $this->amount),
            log: new LogRefund($this->refundId),
        );
    }
}
```

## Scope And Context

A **Context** holds data for a specific execution (an HTTP request, CLI arguments, or WebSocket payload). A **Scope** owns the execution machinery (cancellation, services, cleanup, and diagnostics). In Phalanx handlers, you receive a Context and access the runtime via `$ctx->scope`.

## Use Cases

Phalanx is built for long-running PHP. The managed execution model applies the same structural safety to:

- HTTP APIs, SSE streams, request diagnostics, middleware, and route dispatch
- CLI tools, interactive commands, supervised console flows, and process lifecycles
- terminal UIs with screens, stores, input bindings, settings, devtools, and model request inspection
- WebSocket servers and clients
- worker processes and structured parallelism
- UDP and network tooling
- reverse proxies, load balancers, and operational sidecars
- devops scripts that need cancellation, supervision, retries, and runtime visibility
- AI harnesses that need provider adapters, tool calls, grants, effects, and MCP

## Demos

The repository includes runnable demos for all major framework surfaces.

| Demo | Covers | Command |
| --- | --- | --- |
| [Aegis kernel](demos/aegis) | runtime policy, scope supervision, cancellation, singleflight, runtime memory | `composer demo:aegis` |
| [Stoa HTTP](demos/stoa) | routing, JSON APIs, realtime SSE, runtime lifecycle, diagnostics | `composer demo:stoa` |
| [Archon CLI](demos/archon) | commands, interactive input, supervised concurrency, lifecycle, diagnostics | `composer demo:archon` |
| [Hydra workers](demos/hydra) | workers, structured parallelism, cancellation behavior | `composer demo:hydra` |
| [Athena AI](demos/athena) | streaming providers, SDK coexistence, supervised tool approvals | `composer demo:athena` |
| [Surreal](demos/surreal) | in-memory RPC and live queries | `composer demo:surreal` |

## Installation

Phalanx uses PIE, PHP's modern extension installer. `phalanx-php/cli` ships the doctor and the Swoole installer with platform-aware flag selection.

```bash
composer require phalanx-php/cli

# Check your environment (PHP version, extensions, build tooling)
vendor/bin/phalanx doctor

# Install Swoole via PIE with optimal platform flags
vendor/bin/phalanx swoole:install
```

`swoole:install` runs `pie install swoole/swoole-src` with guided flag selection. Defaults cover TLS, sockets, HTTP/2, and cURL hooks; optional flags cover MySQL, Postgres, c-ares, io_uring, and custom OpenSSL paths.

## Benchmarks

The managed runtime tax is about 4%. Every unit of work flows through the full Aegis kernel: scope creation, supervisor registration, cancellation propagation, disposal, and ledger updates. The current numbers keep that cost close to raw Swoole.

> PHP 8.4.16, Swoole, Apple M-series.
> Run `composer bench:aegis` and `composer bench:stoa` to reproduce.

<details>
<summary><strong>Context switching</strong> - 1M managed switches vs raw Swoole vs raw Fibers</summary>

1,000 units x 1,000 suspends per iteration. The Fiber number is the theoretical floor. No Phalanx code path can reach it because all suspension routes through Swoole's reactor. The number that matters is managed vs raw Swoole.

| Tier | Mean (us) | P95 (us) | Ops/sec | Note |
|------|-----------|----------|---------|------|
| PHP Fiber | 150,651 | 170,983 | 6.64 | raw PHP baseline |
| Swoole Coroutine | 574,041 | 587,353 | 1.74 | Swoole scheduler only |
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
