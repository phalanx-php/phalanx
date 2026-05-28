# Custom PHPStan Rules for the Phalanx Static-Analysis Extension

Notes for the eventual `phalanx/phpstan-extension` package. Each rule below targets a specific footgun or invariant that the substrate POC has surfaced. Rules graduate from "noted in this file" to "scaffolded in the extension" as the framework rewrite begins.

Rule IDs use the `phalanx.<area>.<short>` convention.

---

## Cancellation & coroutine discipline

### `phalanx.cancellation.rawSleep`

**What it catches**: direct calls to `Swoole\Coroutine::usleep`, `Swoole\Coroutine::sleep`, or `OpenSwoole\Coroutine::usleep` inside any class implementing `Scope`, `TaskScope`, or `ExecutionScope`, OR inside a method whose first parameter type-hints any of those.

**Why**: raw `Co::sleep` does not honor scope-level cancellation. User tasks must call `$scope->delay()` for timeouts and parent cancellation to propagate.

**Allowed**: calls inside `AegisSwoole\Concurrency\Co` itself (the helper that translates the substrate primitive) and inside framework infrastructure (`ExecutionLifecycleScope` internals).

**Severity**: error.

---

### `phalanx.cancellation.translatePattern`

**What it catches**: any new method on `ExecutionLifecycleScope` (or any class implementing `Suspendable`) that calls `Coroutine::usleep`, `Channel::pop`, `Channel::push`, or other suspending Swoole primitives WITHOUT either:
- following the `try { $r = ...; if ($r === false || Coroutine::isCanceled()) throw new Cancelled() } finally { $unregister() }` pattern, or
- delegating to `$this->call(...)` which already implements that pattern.

**Why**: every suspending primitive must translate OpenSwoole's interruption-via-return-value into a thrown `Cancelled` so user code's exception flow is consistent.

**Severity**: error.

---

### `phalanx.cancellation.coroutineCidLeak`

**What it catches**: `Coroutine::getCid()` captured into a closure that lives longer than the current method call, without being checked via `Coroutine::exists($cid)` before use.

**Why**: cids are reused after the coroutine ends. A stale listener firing `Coroutine::cancel($staleCid)` would cancel an unrelated coroutine that happened to receive the recycled cid.

**Severity**: warning (escalates to error if the listener calls `Coroutine::cancel` directly).

---

### `phalanx.cancellation.noCancelledShortcut`

**What it catches**: `catch (Throwable)` blocks that swallow exceptions without first checking for `Cancelled` (or its subclasses).

**Why**: cancellation must propagate. Catching `Throwable` and continuing silently turns a cancellation into a "task succeeded" result, violating the supervisor model.

**Severity**: error. Allows opt-out via `@phpstan-ignore-line` with mandatory explanatory comment.

---

## Scope hierarchy discipline

### `phalanx.scope.narrowestInterface`

**What it catches**: a method parameter typed `ExecutionScope` whose body uses only `Scope`-level (`service`, `attribute`, `trace`) or `Suspendable`-level (`call`) members.

**Why**: accepting `ExecutionScope` when `Scope` or `Suspendable` would suffice is coupling, not convenience. Narrows the type → narrows the contract → easier testing and decoration.

**Severity**: warning.

---

### `phalanx.scope.staticClosureOnly`

**What it catches**: closures passed to `concurrent`, `race`, `any`, `map`, `series`, `waterfall`, `settle`, `timeout`, `retry`, `defer`, `singleflight` that are NOT declared `static`.

**Why**: non-static closures capture `$this`. In a long-running coroutine context this is a reference-cycle leak (the closure holds the enclosing object, the enclosing object reaches the closure via the dispatch graph). aegis enforces this at runtime in `Task::of`; a static-analysis equivalent catches it earlier.

**Severity**: error.

---

### `phalanx.scope.disposeRegisterAfterDispose`

**What it catches**: `$scope->onDispose(...)` called inside a method that has already returned from `$scope->dispose()` (rare, but happens when handlers chain through async paths).

**Why**: registering after dispose silently fires the callback immediately (POC behavior) which surprises callers who expect deferred cleanup.

**Severity**: warning.

---

## Concurrency primitive discipline

### `phalanx.concurrency.waitGroupAddTotal`

**What it catches**: any usage of `OpenSwoole\Core\Coroutine\WaitGroup` (or any class implementing similar add/done/wait semantics) where `add()` is called inside a loop that also calls `Coroutine::create()`.

**Why**: WaitGroup must be pre-counted before spawning to avoid: (a) on the OpenSwoole core impl, throwing `BadMethodCallException` on `done()` past zero when a sync-completing spawn drains the count mid-batch; (b) on custom impls, the signal-once-flips-mid-batch bug. The correct pattern is `new WaitGroup(count($items))` (or `add(count($items))` once) outside the loop, then spawn.

**Severity**: error.

---

### `phalanx.concurrency.channelPushNonBlocking`

**What it catches**: `Channel::push($value)` in a context where the channel might be full (race/any losers, semaphore overflow guard) without an explicit short timeout argument.

**Why**: blocking pushes deadlock when the channel was already filled by a prior push. POC pattern is `push($v, 0.001)` for losers in race/any.

**Severity**: warning.

---

### `phalanx.concurrency.singleflightStateRule`

**What it catches**: any `SingleflightGroup`-shaped state machine that yields between reading `$state[$key]` and writing back to it.

**Why**: the no-lock atomicity claim depends on Swoole's non-preemptive coroutine scheduler. Yielding mid-mutation breaks the invariant.

**Severity**: error.

---

## Service container discipline

### `phalanx.service.bundleRegistration`

**What it catches**: `ServiceBundle::services()` impls that call methods other than the documented `Services` interface methods (`singleton`, `scoped`, `eager`, `config`, `alias`).

**Why**: service registration must be declarative. Bundles that resolve services or perform side-effects during registration break the boot ordering guarantees.

**Severity**: error.

---

### `phalanx.service.scopeContextOnly`

**What it catches**: `$scope->service(X::class)` calls outside an active scope (i.e., in module-level code, outside `__invoke`, outside a service factory).

**Why**: services have lifecycle bound to scopes. Resolving outside any scope leaks the resolved instance and bypasses dispose hooks.

**Severity**: error.

---

### `phalanx.service.disposeHookContract`

**What it catches**: `onDispose` hooks registered on `ServiceConfig` that take a parameter type other than the registered service type.

**Why**: hooks receive the resolved service instance. A wrong type hint hides until a runtime cast fails.

**Severity**: error.

---

### `phalanx.service.middlewareScopeWidth`

**What it catches**: any `ServiceTransformationMiddleware::transform()` impl whose body calls `ExecutionScope`/`TaskExecutor`-only members on the `$scope` parameter (`concurrent`, `race`, `map`, `retry`, `timeout`, `inWorker`, etc.).

**Why**: middleware runs inside the resolution path of `service()` — it must stay synchronous and non-orchestrating. The interface ships `Scope` (the narrowest surface) deliberately. Middleware that orchestrates would re-enter the container during resolution and risk reentrancy bugs in the singleton cache.

**Severity**: error.

---

### `phalanx.service.singletonReentrancy`

**What it catches**: any service factory closure (the closure passed to `ServiceConfig::factory(Closure)`) that resolves a service via `$scope->service(self::class)` of the same type being built.

**Why**: this triggers the LazySingleton recursive-get path. The framework guards against duplicate `creationOrder` entries (which would fire `onShutdown` twice), but a factory that recursively requests its own type indicates broken dependency design — the type cannot construct without already existing. Surface this at static-analysis time.

**Severity**: error.

---

### `phalanx.service.middlewareNoMutation`

**What it catches**: middleware that captures and writes to mutable state across calls (e.g., `$this->cache[$type] = $instance` then re-uses the cache on subsequent transforms).

**Why**: caching belongs to the container's lifetime layer (`LazySingleton`, scoped cache). Middleware caching duplicates that state and can silently desync from the container's view.

**Severity**: warning.

---

## Task system discipline (Phase 3)

### `phalanx.task.singleClass`

**What it catches**: classes with `__invoke` plus other public non-`__invoke` methods that look like task entry points (e.g., `handle`, `execute`).

**Why**: aegis enforces "one task per class." Multi-entry classes force per-method profile attributes, which the type system can't narrow.

**Severity**: warning.

---

### `phalanx.task.behavioralInterfaceRespected`

**What it catches**: classes implementing `Retryable`, `HasTimeout`, `HasPriority` etc. whose `__invoke` does not actually use the declared metadata.

**Why**: declaring a behavioral interface without honoring it is a misleading API. The middleware that respects the interface needs the value to mean something.

**Severity**: warning.

---

### `phalanx.task.middlewareForwardsScope`

**What it catches**: any `TaskMiddleware::handle()` impl that calls `$next()` without arguments (must be `$next($scope)` or `$next($childScope)`).

**Why**: `$next` is typed `Closure(ExecutionScope): mixed`. Bare `$next()` fails at runtime with `ArgumentCountError`. More importantly, forwarding the wrong scope (or no scope) means the inner task body uses the parent's cancellation token instead of the middleware's child token, defeating timeout/composite-cancellation semantics.

**Severity**: error.

---

### `phalanx.task.behavioralOnInvokable`

**What it catches**: classes implementing `Retryable`, `HasTimeout`, etc. that do NOT also implement `Scopeable` or `Executable` (and therefore have no `__invoke` to wrap).

**Why**: behavioral interfaces only mean something when the middleware finds a task to wrap. A bare `Retryable` with no task body is a no-op declaration that wastes middleware-chain cycles.

**Severity**: warning.

---

## Worker dispatch discipline (Phase 4)

### `phalanx.worker.noClosureBoundary`

**What it catches**: `inWorker()` calls that pass a `Closure` (or a `Task::of(closure)` wrapping one).

**Why**: closures cannot be serialized across process boundaries. Workers receive class-string + args only.

**Severity**: error.

---

### `phalanx.worker.serviceProxyOnly`

**What it catches**: in worker-side code (any class invoked under a `WorkerScope`), `$scope->service(X)` calls where X is registered as `scoped` rather than `singleton`.

**Why**: scoped services don't survive an IPC roundtrip. The worker can only proxy singletons (which the parent owns app-wide).

**Severity**: error.

---

### `phalanx.worker.procPipeBlockingRead`

**What it catches**: `fread()` / `fgets()` / `stream_get_contents()` calls on a stream resource that originated from `proc_open()` stdin/stdout/stderr pipes, anywhere inside a coroutine context (`Coroutine::create`, `Coroutine::run`, methods on classes implementing `Suspendable`).

**Why**: `SWOOLE_HOOK_ALL` includes `HOOK_PROC` but does NOT hook reads on the resulting pipe handles. A blocking `fread` on a `proc_open` pipe stalls the entire OpenSwoole scheduler — every other coroutine in the process freezes until the read returns or the child writes data. Use `stream_select` (which IS hooked) to wait, then `fread` after readiness.

**Allowed pattern**:
```php
stream_set_blocking($pipe, false);
$r = [$pipe]; $w = null; $e = null;
$ready = stream_select($r, $w, $e, $timeoutSec, 0);
if ($ready > 0) { $chunk = fread($pipe, 8192); }
```

**Severity**: error.

---

### `phalanx.worker.openSwooleProcessForbidden`

**What it catches**: direct construction of `OpenSwoole\Process` anywhere in the codebase.

**Why**: OpenSwoole 26 refuses to instantiate `OpenSwoole\Process` while async-io threads are running (i.e., inside any `Coroutine::run` context). The framework spawns workers via `proc_open()` instead, which is hooked by `SWOOLE_HOOK_ALL` and works under coroutine contexts. Direct `OpenSwoole\Process` use will fail at runtime with "unable to create OpenSwoole\Process with async-io threads".

**Allowed**: framework infrastructure inside the `AegisSwoole\Worker` namespace MAY use `OpenSwoole\Process` for worker-pool patterns that explicitly disable HOOK_ALL on a sub-process — none currently exist, but the rule is namespace-scoped to leave that door open.

**Severity**: error in application code, allowed in `AegisSwoole\Worker` infrastructure.

---

### `phalanx.worker.serializeBoundary`

**What it catches**: tasks passed to `inWorker()` whose constructor parameters or properties are typed `resource`, `Closure`, or another non-serializable type.

**Why**: PHP `serialize()` silently drops or fails on these. The worker receives a partial payload or an unserialize error. Catch at static-analysis time.

**Severity**: error.

---

## Style / safety

### `phalanx.style.noEnvAccess`

**What it catches**: `getenv()`, `$_ENV`, `$_SERVER` reads anywhere outside the bootstrap entry script.

**Why**: aegis flows config through `array $context` from symfony/runtime. Direct env reads break injection and testability.

**Severity**: error. Allow opt-out only in `bin/*.php` entrypoints.

---

### `phalanx.style.noBlockingIO`

**What it catches**: `file_get_contents`, `file_put_contents`, `fopen`/`fread`/`fwrite`, `curl_exec`, `PDO::query`, `sleep`, `usleep`, `pcntl_*`, etc. inside any class reachable from a `Scope` parameter.

**Why**: HOOK_ALL covers many of these but not all (notably `pcntl_*` and some legacy stream wrappers). Bypassing HOOK_ALL blocks the entire coroutine scheduler.

**Severity**: warning.

---

## Notes for extension implementation

- Build as a phpstan extension package: `phalanx/phpstan-extension`.
- Each rule is a class implementing `PHPStan\Rules\Rule` with a typed `getNodeType` and a `processNode` returning errors with stable identifiers (`phalanx.<area>.<short>`).
- Ship `extension.neon` that consumers `include:` in their `phpstan.neon`.
- Provide a baseline-friendly tier: rules default to `error`, but consumers can lower to `warning` via parameter.
- Test each rule against a fixture file demonstrating both passing and failing code.
- Pair every rule with a documented "why" link to this file or to a canonical example in the framework's test suite.
