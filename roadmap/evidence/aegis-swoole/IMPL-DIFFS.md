# Implementation Differences vs Phalanx Aegis

Rolling log of every place the POC's behavior, signature, or mechanics deviate from the canonical `phalanx/packages/phalanx-aegis` (and adjacent packages). Each entry: what differs, why, what to carry into the framework rewrite.

## API surface translations

### `Suspendable::await(PromiseInterface) -> Suspendable::call(Closure)`

- **Aegis**: `await(PromiseInterface $promise): mixed` races the React promise against the cancellation token.
- **POC**: `call(Closure $fn): mixed` runs the closure in the calling coroutine. HOOK_ALL handles I/O suspension transparently; cancellation is enforced by registering an `onCancel` listener that calls `Coroutine::cancel($currentCid)` for the duration of the call.
- **Rewrite decision**: the React promise abstraction does not exist on Swoole. Drop `await(PromiseInterface)` and standardize on `call(Closure)`. If a Promise-shaped surface is needed for migration ergonomics, add a thin adapter that wraps a closure but do not require it.

### `TaskExecutor` accepts `Closure` alongside `Scopeable|Executable`

- **Aegis**: strict union `Scopeable|Executable` — closures must be wrapped in `Task::of(...)`.
- **POC**: union loosened to `Scopeable|Executable|Closure` for ergonomics. The runtime invokes any of the three via `($task)($scope)` so all three work uniformly.
- **Rewrite decision**: keep aegis's strict union. Closure acceptance was a POC-only concession to keep test code compact while `Task::of` arrives in Phase 3. The framework should reject raw closures at the API boundary so all tasks are first-class objects with identity.

## Cancellation translation (Phase 0 substrate finding)

### `Coroutine::cancel` semantics differ between Swoole and OpenSwoole

- **Native Swoole** (which aegis targets indirectly via ReactPHP fibers): `Coroutine::cancel($cid)` raises `Swoole\ExitException` in the target coroutine.
- **OpenSwoole 25.x** (POC target): `Coroutine::cancel($cid)` interrupts the currently-suspended call (`usleep`/`Channel::pop`/etc. return `false`) and sets `Coroutine::isCanceled()` to true. The cancelled coroutine resumes normally and would otherwise keep running.
- **POC translation pattern**:
  - `Co::sleep(float)` checks `Coroutine::isCanceled()` after `usleep` returns and throws `Cancelled` if true.
  - Concurrency primitive child wrappers (`concurrent`, `race`, `any`, `map`, `settle`) catch the post-task `isCanceled()` flag and convert results into `Cancelled` errors before joining.
  - `call()` registers an `onCancel` listener that issues `Coroutine::cancel` on the current cid and translates either a thrown `Cancelled`, an interrupted I/O return, or a `Throwable` raised after `isCanceled()` flipped.
- **Rewrite decision**: every primitive that suspends MUST translate. The pattern is mechanical and should be encoded in a base helper or trait so new primitives don't reinvent it.

### `Co::sleep` is not scope-aware; `$scope->delay()` is

- **POC**: raw `Co::sleep(s)` only honors Swoole-level cancellation (`Coroutine::cancel`). `$scope->delay(s)` routes through `$scope->call()` which registers a token listener.
- **User-task implication**: a user task body calling `Co::sleep` directly will not be interrupted by a scope-level token cancel (e.g. timeout). Tasks must use `$scope->delay()` to opt in.
- **Rewrite decision**: this is a real API distinction. Either (a) keep the distinction and make it explicit in docs, (b) make `Co::sleep` look up the current scope from `CoroutineScopeRegistry` and auto-register, or (c) ban direct `Co::sleep` from user code via PHPStan rule.

## Scope hierarchy

### Stream surface

- **Aegis**: `StreamContext` is its own interface, `ExecutionScope` composes it for emitter integration.
- **POC**: same surface preserved; styx/emitter implementation is out of scope.
- **Rewrite decision**: no change.

### `withAttribute` returns `ExecutionScope` (covariant)

- **Aegis**: same.
- **POC**: same.

## Service container

### Autowiring

- **Aegis**: `ServiceGraphCompiler` reflects on constructors to resolve dependency types automatically.
- **POC**: explicit factory closures via `ServiceConfig::factory(Closure)` plus `ServiceConfig::needs(...$types)` for declared dependencies. Dependencies are resolved by name and passed positionally to the factory closure.
- **Rewrite decision**: port aegis's reflection-based autowiring. The POC's explicit-factory model is a pragmatism to keep the substrate POC small.

### `ServiceTransformationMiddleware`

- **Aegis**: middleware that wraps `service()` resolution to allow logging, decoration, etc.
- **POC (Phase 2 done)**: interface in `AegisSwoole\Service`. Registered via `ApplicationBuilder::serviceMiddleware(...$mws)`. Threaded into `ExecutionLifecycleScope` constructor, applied inside `service()` around the build closure. Singleton resolution wraps the chain so the cached instance is the post-middleware one. First-registered middleware runs outermost; chain order verified by scenario.
- **`$type` typed as `string`, not `class-string`**: the chain is invoked with the *resolved* (post-alias) type from `ServiceGraph::alias()`, which returns `string`. Tightening the interface to `class-string` would force a cast at every call site. Documented in the interface PHPDoc.
- **Rewrite decision**: keep the chain shape. The scope passed to `transform()` is the narrow `Scope` interface (not `ExecutionScope`) — middleware should not orchestrate. Enforce via PHPStan rule `phalanx.scope.narrowestInterface`.

### Lifecycle hook firing

- **`onInit`**: fires inside `ExecutionLifecycleScope::build()` immediately after factory invocation, before the instance is cached. Both lazy and eager singletons + scoped services get it.
- **`onStartup`**: fires inside `LazySingleton::startupEager()` after the eager instance is cached. Only fires for **eager singletons**. Lazy singletons that are never resolved at startup never fire onStartup. (Open question for the rewrite: should onStartup fire on first lazy resolution too? Aegis behavior to verify.)
- **`onDispose`**: fires inside `ExecutionLifecycleScope::dispose()` for scoped services in reverse creation order. Does not fire for singletons (those are app-wide).
- **`onShutdown`**: fires inside `LazySingleton::shutdown()` (called from `Application::shutdown()`) for all singletons in reverse creation order.
- **POC additional**: `Application::startup()` disposes the bootstrap-scope it creates for eager resolution, in a `finally` block. Prevents an undisposed scope from leaking dispose hooks across the app lifetime.

## Task system & middleware (Phase 3)

### `Task::of` static-closure enforcement

- **Aegis**: `Task::of(Closure $fn)` reflects on the closure and rejects non-static closures via `RuntimeException`. Captured `$this` in async contexts creates reference cycles the cycle collector cannot deterministically reap.
- **POC**: same — `ReflectionFunction::isStatic()` check in `Task::of`. `Task` implements `Executable` and proxies via `__invoke`.
- **POC notes**: `Task::create(Closure, TaskConfig)` and `with(...)` chaining (aegis surface) are NOT in the POC — keeping the surface minimal until behaviors land via behavioral interfaces. Class-based tasks implement `Retryable`/`HasTimeout`/`Traceable` etc. directly.

### Behavioral interfaces in `AegisSwoole\Task`

| Interface | Method | Purpose |
|---|---|---|
| `Retryable` | `retryPolicy(): RetryPolicy` | RetryMiddleware wraps execute in `$scope->retry(..., $policy)` |
| `HasTimeout` | `timeoutSeconds(): float` | TimeoutMiddleware wraps execute in `$scope->timeout($s, ...)` |
| `Traceable` | `traceName(): string` | TraceMiddleware emits `TraceType::Execute` events with phase=start/end/error |
| `HasPriority` | `priority(): int` | Reserved for worker mailbox dispatch (Phase 4) |
| `UsesPool` | `poolName(): string` | Reserved for worker pool routing (Phase 4) |

### `TaskMiddleware` chain

- **Shape**: `handle(Scopeable|Executable|Closure $task, ExecutionScope $scope, Closure $next): mixed` where `$next` accepts `(ExecutionScope $scope): mixed`. Middleware that creates a child scope (TimeoutMiddleware) MUST pass that child scope to `$next` so the inner task body honors the new cancellation token.
- **Order**: first-registered runs outermost. Same convention as ServiceTransformationMiddleware. Verified by `middleware.chain.order.preserved` scenario.
- **Wiring**: registered via `ApplicationBuilder::taskMiddleware(...$mws)`, threaded through `Application` into `ExecutionLifecycleScope` constructor, applied inside `execute()`.
- **Aegis**: same shape (Promise-based plumbing under the hood instead of coroutine-based, but the surface is the same).

### `executeFresh` propagates middleware

- **POC**: derived scopes from `withAttribute`, `executeFresh`, and `timeout` all propagate the parent's `serviceMiddlewares` and `taskMiddlewares` lists. Middleware chains are app-scoped.
- **Open**: aegis allows per-call middleware overrides (`$scope->withTaskMiddleware(...)` or similar). Not in the POC. Decide for the rewrite.

## Concurrency primitives

### `WaitGroup`

- **Native Swoole**: ships `Swoole\Coroutine\WaitGroup`.
- **OpenSwoole core**: ships `OpenSwoole\Core\Coroutine\WaitGroup` in the `openswoole/core` userland package (NOT in the extension itself).
- **POC**: uses `OpenSwoole\Core\Coroutine\WaitGroup` directly. The core impl is `Channel(1)`-backed with `add(int $delta)`, `done()`, `wait(float $timeout = -1): bool`, and a `waiting` flag that throws `BadMethodCallException` on misuse (negative counter, concurrent add+wait, reuse before previous wait returned).
- **Caller invariant**: must `add(count($tasks))` BEFORE spawning. The OpenSwoole impl throws on `done()` past zero, so each spawn must call `done()` exactly once (we do this in a `finally` block per child coroutine).
- **Rewrite decision**: depend on `openswoole/core` and use its WaitGroup. No need to ship a custom one. Document the pre-add-total rule via PHPStan rule (see `PHPSTAN-RULES.md`).

### Race / Any error semantics

- **Aegis (`React\Promise\race`)**: rejects with the first rejection.
- **POC `race()`**: same — first message off the channel wins, regardless of ok/err.
- **Aegis (`React\Promise\any`)**: returns first success; if all reject, throws `AggregateException`.
- **POC `any()`**: same; uses custom `AggregateException`.

### `singleflight` semantics

- **Aegis**: in-flight-only. Once the in-flight task resolves, the entry is removed; subsequent calls re-run the task.
- **POC**: same.
- **Note**: the POC's "no lock needed" claim relies on Swoole's non-preemptive coroutine scheduler. Mutations to `$state[$key]` between yield points are atomic from any single coroutine's view. Encoded as design risk #9 in the plan.

### `defer` lifecycle

- **Aegis**: detached fiber, errors logged, cancellation observed only via `throwIfCancelled()` cooperative checks.
- **POC**: spawned coroutine tracked by cid; on `dispose()` the scope iterates `deferredCids` and calls `Coroutine::cancel` to interrupt any still-running deferreds. Errors are logged via `Trace`.
- **Rewrite decision**: the POC behavior (cancel-on-dispose) is stricter than aegis. Aegis lets deferreds outlive scope dispose. Decide which is correct for the framework — likely the POC's behavior is safer.

### `timeout` token cleanup

- **POC**: `CancellationToken::timeout(s)` registers a `Swoole\Timer::after` callback. `cancel()` clears the timer ID via `Timer::clear` so a deferred timer cannot fire on a cancelled token. `timeout()` always cancels the timeout token in `finally` to clean up unused timers when the task finishes early.
- **Aegis**: `React\EventLoop\Loop::addTimer` + `cancelTimer` equivalent.

## Bootstrap

### Symfony Runtime integration

- **Aegis**: `autoload_runtime.php` calls the application closure with `array $context`.
- **POC**: `bin/run.php` plays the role manually inside `OpenSwoole\Coroutine::run`. Deferred — substrate POC doesn't validate the runtime adapter.
- **Rewrite decision**: port the symfony/runtime integration once the substrate POC is fully validated. Pattern: `Symfony\Runtime` provides `array $context` → POC's `Application::starting($context)` consumes it directly. The integration is one file plus `composer.json` `extra.runtime` config.

### Boot phases

- **Aegis**: `Application::starting($context) -> ApplicationBuilder -> compile(): Application -> startup(): static -> boot(): [AppHost, ExecutionScope] -> shutdown(): void`.
- **POC**: same surface, except `boot()` is not implemented as a tuple-returning combinator. Use `compile()->startup()` then `createScope()` directly. The aegis `boot()` shape can be added trivially as a convenience method.

### LazySingleton recursive `get` fix (Phase 5 substrate finding)

- **Bug**: `Application::startup()` resolves eager singletons via `$rootScope->service($type)`. The scope's `service()` calls back into `LazySingleton::get($type, $build)` to find/build the singleton. The outer `startupEager` path ALSO called `LazySingleton::get($type, $factory)` where `$factory` calls `$rootScope->service($type)`. The inner `get()` cached the instance and pushed onto `creationOrder`. The outer `get()` then re-cached and re-pushed — duplicating the type in `creationOrder`. On `shutdown()`, the `onShutdown` hook fired twice.
- **Fix**: `LazySingleton::get()` re-checks `isset($this->instances[$resolved])` after `$build()` returns. If the inner build already cached the instance, skip the second assignment + creationOrder append.
- **Rewrite decision**: bake the re-check pattern into the framework's singleton cache. This is a real footgun for any factory-via-scope-resolves-self path.

## Worker dispatch (Phase 4)

### Process spawning: `proc_open` over `OpenSwoole\Process`

- **Plan called for**: `OpenSwoole\Coroutine\Process` (per the migration table) or `OpenSwoole\Process`.
- **Substrate finding**: OpenSwoole 26 refuses to instantiate `OpenSwoole\Process` while the runtime's async-io thread pool is active (the coroutine context that `Coroutine::run` establishes). Error: "unable to create OpenSwoole\Process with async-io threads".
- **POC**: switched to standard PHP `proc_open()` + the returned pipes. `proc_open` is part of `SWOOLE_HOOK_ALL`, so reads/writes on the resulting pipes yield the calling coroutine to the scheduler. The IPC contract is unchanged.
- **Rewrite decision**: depend on `proc_open` for child spawning. Document the OpenSwoole-Process restriction. If the upstream extension lifts the restriction in a later release, evaluate switching.

### Task transport: PHP `serialize` over JSON

- **Plan called for**: JSON-encoded TaskRequest with `class-string` + args.
- **POC**: TaskRequest carries a base64-wrapped `serialize($task)` blob. The whole task instance (including its constructor state) ships across. Child unserializes and runs `__invoke($workerScope)`.
- **Why**: matches the existing `inWorker(Scopeable|Executable $task)` API. The user passes an instance, not a class-string + args. PHP serialize cleanly rejects Closures (uncloneable), which is the natural enforcement point for the closure-cannot-cross-boundary invariant.
- **Trade-off**: child must autoload the same vendor + src as parent. Codec wraps the serialize blob in a JSON envelope (kind/id) for line-based framing.

### Worker `WorkerScope` is not a service-proxy

- **Plan called for**: child-side `WorkerScope` whose `service()` calls the parent over IPC via a `ParentServiceProxy`.
- **POC**: child runs a fresh in-process `Application` with an empty service graph. `$scope->service(X)` inside a worker task throws "No service registered". Concurrency primitives (concurrent/race/map/etc.), `delay`, and `cancellation` work in-worker because they don't need parent state.
- **Rewrite decision**: the service-proxy is a real feature for the framework but adds significant IPC complexity (request multiplexing, serialization rules for return values, nested-scope lifecycle). Defer to the framework rewrite. The POC validates that the substrate (process spawning + IPC + scope + task dispatch) works; the proxy layer is mechanical to add.

### Cancellation across the boundary

- **POC**: parent-side cancellation does NOT interrupt an in-flight worker task. The parent waiter (Channel) is closed on supervisor shutdown, but the child keeps running until its task completes naturally.
- **Plan called for**: parent token cancel → kill worker process. Implemented at `WorkerSupervisor::shutdown()` granularity, not per-task.
- **Rewrite decision**: per-task cancellation needs a Cancel message frame and child-side cooperative checks (or a `Process::kill` for non-cooperative kill). Defer; not load-bearing for substrate validation.

### Crash detection + restart

- **POC**: Worker reader coroutine detects pipe close and pushes WorkerCrashedException to all pending waiters. No automatic restart — the supervisor stays "Crashed" for that slot. `SupervisorStrategy::RestartOnCrash` is not implemented.
- **Rewrite decision**: implement via a supervisor coroutine that periodically polls `proc_get_status()` and replaces crashed workers per the configured `SupervisorStrategy`.

### Mailbox overflow

- **POC + plan**: `Mailbox::push` throws `OverflowException` synchronously when the bounded `Channel(limit)` reports full.
- **Tested**: as a unit-style scenario rather than dispatch-integrated, because the writer drains the slot fast enough that the dispatch path is race-sensitive.

### Original anticipated diffs

- `React\ChildProcess\Process` → `proc_open()` (NOT `OpenSwoole\Process`, see above)
- React promise-based dispatch → coroutine-based dispatch via per-worker reader/writer cids
- Crash detection: React loop polling vs `proc_get_status()` in a supervisor coroutine (deferred)

## Library integration (Phase 6+)

### HTTP client (Phase 6)

- **POC**: `AegisSwoole\Http\HttpClient` wraps `OpenSwoole\Coroutine\Http\Client`. Constructor takes `Suspendable` (typically `DeferredScope`); each request runs through `$scope->call(...)` so scope cancellation interrupts the in-flight client and translates to `Cancelled`.
- **Substrate finding**: `OpenSwoole\Coroutine\Http\Client` honors `Coroutine::cancel` — when the calling coroutine is cancelled mid-`execute()`, the client returns `false` with `errCode=0, statusCode=0`. POC translates that to `Cancelled` to preserve aegis semantics.
- **Substrate finding**: HTTP server fixture must be spawned as a child process (proc_open). `OpenSwoole\Http\Server::start()` blocks the calling thread; you cannot run a server and a scenario battery in the same `Coroutine::run`. Same restriction applies to `OpenSwoole\Process` (see Phase 4 finding) — child server is the supported path.
- **Open**: connection pooling. POC opens/closes a fresh `Client` per request. The framework should expose a pool (e.g., wrapping `OpenSwoole\Core\Coroutine\Pool\ClientPool`) for high-throughput call sites.

### Postgres / `pdo_pgsql` (Phase 7) — major substrate finding

- **Substrate finding**: OpenSwoole 26 does NOT include `pdo_pgsql` in `SWOOLE_HOOK_ALL`. The hook flag set has `HOOK_TCP/UDP/UNIX/SSL/TLS/STREAM_*/FILE/STDIO/SLEEP/PROC/CURL/NATIVE_CURL/BLOCKING_FUNCTION/SOCKETS` — no `HOOK_PDO`, no `HOOK_PDO_PGSQL`. PDO Postgres calls block the calling coroutine; no scheduler yield, no `Coroutine::cancel` interruption.
- **Native async client missing**: `OpenSwoole\Coroutine\PostgreSQL` (the historical async client used by `OpenSwoole\Core\Coroutine\Client\PostgresClientFactory`) is NOT loaded in OpenSwoole 26. Falling back to `OpenSwoole\Core\Coroutine\Client\PDOClient` + `PDOConfig` with `driver=pgsql` works for sync queries but inherits the no-hook limitation.
- **Consequence 1 — concurrency**: in-coroutine concurrency primitives (`concurrent`, `map`, etc.) accept Postgres tasks but the queries serialize, not interleave. The pool's connection slots are unused for parallelism.
- **Consequence 2 — cancellation**: scope-level cancellation cannot interrupt an in-flight `pdo_pgsql` call. `$scope->call()` registers the cancel listener but `Coroutine::cancel` has nothing to interrupt.
- **POC workaround for true parallelism**: `inWorker(...)`. Each worker process opens its own `PostgresPool`; processes run truly in parallel. Tested: 4 workers × 200ms `pg_sleep` complete in ~200ms wall time vs ~800ms if serialized.
- **POC workaround for hard timeouts**: server-side `SET statement_timeout = N` per session. Postgres raises a SQLSTATE 57014 (PDOException) before the query completes. The framework's `TimeoutMiddleware` is not viable for this driver path.
- **POC parameter binding gotcha**: pdo_pgsql does NOT bind `$1, $2, ...` placeholders through `PDO::execute([…])` — values arrive as NULL. Use `?` placeholders. Documented in `PostgresPool::query()` examples.
- **Rewrite decision**: `phalanx-postgres` should ship a native async Postgres client (libpq async over the socket layer) rather than depending on `pdo_pgsql`. Until then: document `inWorker` as the parallelism path, document `statement_timeout` as the cancellation path, and add a PHPStan rule flagging in-coroutine cancellation expectations on Postgres tasks.
- **`PostgresPool` API**: thin wrapper over `ClientPool` + `PDOClientFactory`. `query(sql, params)`, `execute(sql, params)`, `close()`. `close()` registered via `onShutdown` hook on the singleton service.

### Agentic workflows / LLM client (Phase 8)

- **POC**: `AegisSwoole\Llm\LlmClient` is a minimal OpenRouter chat-completions client over `OpenSwoole\Coroutine\Http\Client`. `AegisSwoole\Agent\AgentTask` is a worker-shippable `Executable` carrying role + system prompt + transcript + LlmConfig (key embedded so the child needs no service container plumbing).
- **Bidirectional coordination**: parent-mediated relay. Parent owns the canonical transcript; each turn it ships the receiving agent's full history to a worker, gets the reply, appends to its own state, then sends to the next agent. This is the substrate-level alternative to the deferred Phase 4 service-proxy and has the advantage of being entirely sequential at the IPC layer (no nested agent→parent→agent calls to multiplex).
- **Substrate finding (major) — proc_open pipes don't auto-yield under HOOK_ALL**: the parent-side reader coroutine's `fread()` on a `proc_open` stdout pipe BLOCKS the entire OpenSwoole 26 scheduler while it waits for data. SWOOLE_HOOK_ALL includes `HOOK_PROC` (1024) but pipe-side reads are NOT hooked; only `proc_open` itself is. Same applies to `STDIN` reads inside the child process.
- **Fix**: switch both ends to non-blocking + `stream_select` with a small timeout. `stream_select` IS hooked (HOOK_STREAM_FUNCTION/SELECT) and yields cleanly. Applied to:
  - `ProcessHandle::readLine()` — parent-side
  - `WorkerRuntime::run()` STDIN read loop — child-side
- **Rewrite decision**: bake the stream_select pattern into the `phalanx-hydra` worker layer. Document with a PHPStan rule prohibiting blocking `fread`/`fgets` on coroutine-spawned pipe handles.
- **Cancellation in the worker boundary**: the OpenSwoole HTTP client honors HOOK_ALL, so an LLM call inside a worker DOES yield to the scheduler. Parent-side cancel currently surfaces as a `WorkerCrashedException`-style error when the supervisor tears down the pipes. Cleaner cancel propagation (Cancel-message frame in the protocol) is deferred to the framework rewrite — see Phase 4 IMPL-DIFFS for the discussion.
- **Secret handling**: `bin/run.php` resolves the API key at runtime via `op item get "OpenRouter | Havy.tech | Env: all" --field "API Key " --reveal` and passes it through `Application::starting($context)` so it never lands in source. AgentScenarios skip silently if the key isn't available.

### `DeferredScope` for long-lived service injection (Phase 6)

- **Aegis**: `DeferredScope` exists in phalanx-aegis backed by `FiberScopeRegistry`.
- **POC (Phase 6)**: implemented `AegisSwoole\Scope\DeferredScope` backed by `CoroutineScopeRegistry`. Implements `Suspendable + Cancellable` for the call paths HttpClient needs. Add more delegations as services demand them.
- **Harness change**: tests harness now installs the per-scenario scope into `CoroutineScopeRegistry` for the duration of the test callback, mirroring what `ExecutionLifecycleScope::execute()` does internally. Without this, `DeferredScope::resolve()` would throw because no scope is installed in the test coroutine.

## Trace

### Sinks

- **Aegis**: `Trace` accepts pluggable sinks (logger integration, OpenTelemetry, etc.).
- **POC**: in-memory event log only.

## Phase tracking

| Phase | Status | Differences captured |
|---|---|---|
| 0 | Done | Cancellation translation, OpenSwoole class differences, `Co::sleep` semantics |
| 1 | Done | WaitGroup pre-add rule, scope.delay vs Co::sleep, AggregateException, singleflight in-flight-only, timeout token cleanup |
| 2 | Done | ServiceTransformationMiddleware chain shape, lifecycle-hook firing rules, root-scope dispose during eager startup |
| 3 | Done | Task::of static-closure rejection, behavioral interfaces, TaskMiddleware chain w/ scope-forwarding $next, RetryMiddleware/TimeoutMiddleware/TraceMiddleware |
| 4 | Done (minimum-viable) | proc_open spawning, JSON/serialize IPC, RoundRobin dispatcher, mailbox overflow. Service-proxy / per-task cancel / crash-restart deferred to framework rewrite |
| 5 | Done (no symfony/runtime) | ApplicationBuilder full surface (providers, serviceMiddleware, taskMiddleware, withTrace, withWorkerDispatch), Application lifecycle scenarios, LazySingleton::get duplicate-creation-order fix |
| 6 | Done | HTTP client (OpenSwoole\Coroutine\Http\Client) cancellation translation, child-process HTTP server fixture (Server::start blocks), DeferredScope for long-lived service injection, harness installs scope in CoroutineScopeRegistry |
| 7 | Done (with substrate caveats) | pdo_pgsql NOT in SWOOLE_HOOK_ALL → queries serialize and ignore in-coroutine cancellation; inWorker provides true parallelism; statement_timeout provides hard query bounds; $1/$2 placeholders broken, use ? |
| 8 | Done | proc_open pipe reads NOT auto-yielded under HOOK_ALL → use stream_select; LlmClient over OpenSwoole HTTP client; AgentTask worker-shippable; parent-mediated multi-agent debate proves bidirectional coordination via process spawning; secret resolved through 1Password at runtime |
