# Phalanx Swoole POC: innovation and DX review

This review is based on the current code under `poc/aegis-swoole`.
I did not use this file as source material.

## Current Shape

The POC already has more than a sketch:

- `Application` compiles a service graph, owns singleton lifetime, and creates scopes.
- `ExecutionLifecycleScope` is the main runtime surface for services, cancellation, disposal, and orchestration.
- `TaskMiddleware` adds behavior through marker interfaces like `HasTimeout`, `Retryable`, and `Traceable`.
- Swoole coroutine cancellation is translated into the framework's `Cancelled` exception model.
- `DeferredScope` solves long-lived service access to the currently installed coroutine scope.
- `WorkerSupervisor`, `Worker`, and `ParallelWorkerDispatch` prove process-backed parallel execution.
- HTTP, Postgres, worker, agent, cancellation, middleware, and concurrency scenario tests exercise real runtime behavior.

The POC is strongest where it rejects raw substrate leakage: users do not call `Coroutine::create`; they enter through scope methods. That is the right posture.

The weak point is that the supervision model is still implicit. The code has the pieces, but not one central runtime fact representing "this task run exists, is owned, is cancellable, has children, holds resources, and will be reaped."

That should be the next step.

## The Main Move: One Supervisor Execution Path

Right now `execute()` is special:

- It installs the coroutine-local scope.
- It runs task middleware.
- It is where `HasTimeout`, `Retryable`, and `Traceable` behavior applies.

But `concurrent`, `race`, `any`, `map`, `settle`, `defer`, and `inWorker` mostly bypass that path and invoke tasks directly.

That creates a DX trap:

```php
$scope->execute(new TimeoutBoundTask(...));      // timeout middleware applies
$scope->concurrent([new TimeoutBoundTask(...)]); // timeout middleware does not apply today
```

That is the kind of distinction users should never have to learn.

The best next architectural move is to extract a private supervisor/scheduler object and make every dispatch primitive create the same internal `TaskRun`.

```text
Application
  Supervisor
    ScopeRun
      TaskRun
        CoroutineHandle | WorkerHandle | ThreadHandle
        CancellationToken
        DisposalStack
        Middleware chain
        Resource leases
        Trace span
        Child runs
```

User-facing methods stay ergonomic:

```php
$scope->execute($task);
$scope->concurrent($tasks);
$scope->race($tasks);
$scope->inWorker($task);
```

Internally they all become:

```text
Supervisor::start(TaskDefinition, ParentScope, DispatchMode)
Supervisor::join(TaskRun)
Supervisor::cancel(TaskRun)
Supervisor::reap(TaskRun)
```

This gives Phalanx one answer for cancellation, tracing, middleware, lock ownership, worker placement, disposal, and diagnostics.

## Priority Fixes Before Adding New Surface

### 1. Make child tasks real child scopes

`concurrent()` currently shares the same `ExecutionLifecycleScope` instance across all child coroutines.

That is convenient, but it means siblings share:

- scoped service instances
- dispose stack
- attributes
- single task-local identity, because there is no task identity yet

[CODEX START]

In a safety-first framework, sibling tasks should usually get sibling task scopes. They can inherit parent attributes, singleton services, and the parent cancellation source, but each task run should have its own local disposal stack and local cancellation token.

Suggested semantics:

```text
parent scope cancelled -> every child token cancels
race winner selected   -> losing child tokens cancel
child finishes         -> child scope disposes
parent disposes        -> unfinished children cancel, then dispose
```

That gives users the intuitive rule:

> Each dispatched task owns what it opens.

### 2. Stop cancelling only the coroutine

Several paths call `Coroutine::cancel($cid)` directly. That interrupts the substrate, but it does not make the framework's own cancellation state the source of truth.

The supervisor should cancel a `TaskRun`, not just a coroutine:

```text
TaskRun::cancel()
  -> local CancellationToken::cancel()
  -> resource leases release or mark abandoned
  -> substrate handle interrupt
  -> trace event
```

Then `Coroutine::cancel` is an implementation detail of a larger framework event.

This matters because task code can inspect `$scope->isCancelled` and `$scope->cancellation()`. Those should reflect supervisor truth, not only substrate interruption.

### 3. Run middleware everywhere

Every path that accepts `Scopeable|Executable|Closure` should invoke through the same task runner.

This avoids special cases:

- `Retryable` works in `concurrent`
- `HasTimeout` works in `map`
- `Traceable` works in `defer`
- future resource declarations work in `race`
- worker placement works from any orchestration primitive

The rule should be simple:

> If Phalanx runs a task, Phalanx applies the task contract.

### 4. Make `Executable` and `Scopeable` enforce invocation

Both interfaces are empty today. `ExecutionLifecycleScope::execute()` assumes `($task)($scope)` is valid, but the type system does not enforce it.

At POC level this is fine. For the next slice, give the task contract teeth:

```php
interface Executable
{
    public function __invoke(ExecutionScope $scope): mixed;
}
```

Then decide whether `Scopeable` is still a distinct concept. If the difference is "can receive a narrower scope", the interface should encode that. If not, remove it from the model before it becomes vocabulary debt.

### 5. Replace silent disposal swallowing with supervised disposal reports

`dispose()` currently swallows exceptions. That is safe for cleanup continuation, but weak for DX.

Better model:

- cleanup always continues
- disposal errors become trace events
- scope disposal returns or records a `DisposalReport`
- test harness can assert no leaked disposal errors
- production can log disposal failures without crashing shutdown

Developers should never have to wonder whether cleanup failed.

## DX Features Worth Building Into The POC

### 1. Task tree diagnostics

This is the feature other PHP frameworks do not have.

When something fails, Phalanx should be able to print the live task tree:

```text
Application app-1
  Scope req-7 cancelled=false
    Task fetch-dashboard running 182ms
      waits: postgres.query users
      holds: pool:postgres/main
    Task refresh-cache blocked 179ms
      waits: lock cache:user:42 write
    Task send-email cancelled
      reason: parent scope cancelled
```

This should be powered by the supervisor ledger, not logs.

The DX win is huge:

- deadlocks become visible
- leaks become visible
- stuck tasks have names
- cancellation has causes
- "what is my async app doing?" has an answer

### 2. Named tasks by default

`Traceable` is useful, but optional task names should not be the only identity path.

Task identity can be derived in layers:

1. Explicit task name, if provided.
2. Class name for task objects.
3. File:line for static closures.
4. Generated run id.

`Task::of()` already reflects closures to enforce `static`. Extend that into automatic source identity.

Possible surface:

```php
Task::named('refresh-user-cache', static fn(ExecutionScope $scope) => ...);
Task::of(static fn(ExecutionScope $scope) => ...); // auto name: file.php:42
```

Every trace, error, timeout, and lock report should include that identity.

### 3. Wait reasons

Any time a task suspends through Phalanx, record why.

Examples:

```text
wait: delay 100ms
wait: http GET 127.0.0.1:8080 /slow
wait: postgres SELECT pg_sleep(...)
wait: worker agent-2 request 17
wait: singleflight user:42
wait: lock cache:user:42 write
```

This converts "async PHP is stuck" into a precise explanation.

The implementation can be simple:

```text
Supervisor::aroundWait(TaskRun, WaitReason, Closure)
```

Then `HttpClient`, `PostgresPool`, `SingleflightGroup`, `Worker::submit`, and `delay` all report wait state through the same mechanism.

### 4. Compile-time graph report

`ApplicationBuilder::compile()` currently compiles services. Make it produce a first-class `ApplicationPlan`.

It should be printable:

```text
Services
  HttpClient scoped needs Suspendable
  PostgresPool scoped needs Suspendable, PostgresPoolConfig

Task middleware
  RetryMiddleware
  TimeoutMiddleware
  TraceMiddleware

Worker pools
  default process x 2 mailbox=64 strategy=round-robin

Warnings
  PHX-WORKER-001 UsesPool exists but no pool resolver is configured
```

The report is not just docs. It is a developer feedback surface and a future static analysis bridge.

### 5. Phalanx error codes

The framework should name safety failures.

Examples:

```text
PHX-TASK-001 non-static closure rejected
PHX-TASK-002 task object is not invokable
PHX-SCOPE-001 DeferredScope used outside a supervised task
PHX-CANCEL-001 task ignored cancellation after substrate interrupt
PHX-WORKER-001 closure cannot cross worker boundary
PHX-LOCK-001 lock acquisition order would deadlock
PHX-POOL-001 nested acquire from same pool
PHX-TXN-001 external IO attempted while transaction lease held
```

This is not bureaucracy. It makes Phalanx feel like a safety system instead of a bag of runtime exceptions.

## Resource Safety Innovations

### 1. Resource leases as runtime facts

The five footguns need one mechanism underneath them: supervised leases.

Resources should not only be objects. They should be entries in the task run ledger.

```text
TaskRun fetch-user
  leases:
    pool postgres/main connection#4
    transaction postgres/main tx#81
    lock cache:user:42 write
```

Once leases are visible to the supervisor, Phalanx can reject:

- nested pool acquire
- IO while holding a transaction
- circular cache locks
- orphaned suspension
- non-safe extension calls

The implementation can start runtime-first. Static compile checks can come later.

### 2. Keyed lock registry with read/write modes

The lock registry should belong to the supervisor and expose no raw Swoole primitives.

Suggested model:

```text
LockDomain: cache
LockKey: user:42
LockMode: read | write
LockLease: held by TaskRun
```

Semantics:

- multiple reads may coexist
- write excludes reads and writes
- multi-key acquire sorts canonical keys before acquisition
- a task may re-enter locks it already owns
- lock waiters are queued FIFO per canonical key
- cancellation removes waiters from queues
- release happens on task exit even if the body fails

Userland should not see channels or mutexes. It should see an intention:

```php
$scope->writeLock('cache', "user:{$id}", static fn(TaskScope $scope) => ...);
```

or, eventually, a task declaration that causes the supervisor to acquire the lease before body execution.

### 3. Transaction scope as a narrowed capability

The cleanest way to prevent IO while holding a transaction is not "remember not to do that." It is to make a transaction body receive a narrower scope.

Possible shape:

```text
TransactionScope
  allows: query on same transaction, cancellation, disposal
  denies: arbitrary HTTP, worker dispatch, unrelated pool acquire
```

This does not need to be perfect on day one. Even runtime rejection with a precise error is a differentiator.

Example failure:

```text
PHX-TXN-001 Task SendReceipt attempted HTTP while holding transaction postgres/main.
Move the HTTP call before commit or enqueue it after the transaction scope exits.
```

That is the safety model talking directly to the developer.

### 4. Pool acquisition ownership

`PostgresPool` currently hides pool get/put internally, which is good for user ergonomics. The next step is to expose acquisition to the supervisor.

Not to the user. To the ledger.

```text
pool.get() -> supervisor records PoolLease
pool.put() -> supervisor releases PoolLease
```

Then nested pool acquire becomes mechanically detectable:

```text
PHX-POOL-001 Task ImportUsers attempted to acquire postgres/main while already holding postgres/main.
```

This is one of Phalanx's strongest possible claims.

## Worker and Parallelism DX

### 1. Make worker placement a task contract

`UsesPool` exists but is not wired into `inWorker()` or the supervisor yet.

Rather than making users manually choose `inWorker()` everywhere, treat placement as part of task execution:

```text
task declares: process pool "llm"
scope executes: supervisor routes to process worker
caller sees: normal task result
```

Then these become equivalent from the caller's perspective:

```php
$scope->execute(new SummarizeDocument(...));
$scope->concurrent([new SummarizeDocument(...), new ExtractKeywords(...)]);
```

The supervisor decides inline coroutine vs worker pool from the task contract.

### 2. Add worker health and restart policy

Worker cancellation currently kills the process and marks the worker crashed, but the supervisor does not appear to remove, replace, or quarantine crashed workers.

For POC-plus, add:

- crashed worker quarantine
- restart with backoff
- max restarts per time window
- pending request failure with `RemoteTaskFailure` or `WorkerUnavailable`
- trace events for spawn, crash, restart, drain, kill

This turns process parallelism into supervised parallelism.

### 3. Replace remote exception reconstruction

`ParallelWorkerDispatch` reconstructs exceptions by doing `new $class($message)`. That will fail or lie for many exception types.

Use one framework exception:

```php
final class RemoteTaskFailure extends RuntimeException
{
    public function __construct(
        public readonly string $remoteClass,
        public readonly string $remoteMessage,
        public readonly string $remoteTrace,
        public readonly string $workerId,
        public readonly string $taskId,
    ) {}
}
```

DX improves because the developer sees the remote stack and worker identity instead of a fake local exception.

### 4. Give worker tasks a service projection

`WorkerRuntime` builds an empty application, so worker tasks cannot use normal services unless they manually construct dependencies like `AgentTask` does with `LlmClient`.

That is acceptable for a POC, but it works against framework identity.

Better options:

- `WorkerBundle`: services explicitly available inside workers
- `ServiceProjection`: parent compile pass decides which configs can cross process boundary
- `WorkerContext`: serializable context passed to every worker app at startup
- `RemoteService`: explicit proxy back to parent for rare cases

The important DX rule:

> Worker code should feel like Phalanx code, not a separate mini-framework.

## API Surface Ideas

These are worth prototyping after the supervisor ledger exists.

### 1. `supervise()` as the structured-concurrency primitive

Current methods are useful: `concurrent`, `race`, `any`, `map`, `settle`.

Add one lower-level primitive that exposes the group without exposing Swoole:

```php
$scope->supervise(static function (TaskGroup $group): string {
    $a = $group->start(new FetchProfile(...));
    $b = $group->start(new FetchOrders(...));

    return render($a->join(), $b->join());
});
```

`TaskGroup` gives advanced users:

- start
- join
- cancel
- settle
- deadline
- child names
- group-level disposal

This unlocks complex orchestration without raw channels.

### 2. `TaskHandle` instead of futures or channels

Avoid `Future` naming unless you want to inherit promise/future expectations.

Suggested internal/user-visible name:

```text
TaskHandle<T>
```

Methods:

```php
$handle->join();
$handle->cancel();
$handle->settle();
$handle->state();
$handle->name();
```

The key distinction:

> A handle is not a Swoole primitive. It is a supervisor claim ticket.

### 3. Explicit wait wrappers

Instead of raw `Channel` exposure, provide framework waits:

```php
$scope->waitFor($signal);
$scope->next($stream);
$scope->join($taskHandle);
```

Every wait records a wait reason and participates in cancellation.

### 4. Dev-mode leaked coroutine detector

At scope disposal, assert that every child run has completed, settled, or been cancelled.

Dev-mode failure:

```text
PHX-SCOPE-002 Scope disposed with 1 live child task.
  child: refresh-cache
  started: CacheWarmup.php:44
  wait: postgres query
```

That is exactly the kind of "best in class" async DX Phalanx can own.

### 5. `phalanx doctor`

A CLI command that runs the compile pass and reports:

- OpenSwoole hooks enabled
- Postgres coroutine client available
- unsafe extension list
- service graph cycles
- missing worker scripts
- worker autoload path validity
- task middleware order
- safety policy configuration
- known substrate caveats

The POC already has substrate findings embedded in comments. Turn them into executable checks.

## Specific Code Smells To Address

These are not criticisms of the POC. They are the places I would tighten before calling the model coherent.

1. `ExecutionLifecycleScope` is doing too much.
   It is scope, scheduler, service resolver, middleware runner, cancellation bridge, and task group implementation. Extracting a supervisor/scheduler will make the hierarchy real.

2. `concurrent`, `race`, `any`, `map`, and `settle` duplicate coroutine child handling.
   This should become one `startChildTask()` path.

3. `SingleflightGroup::do()` waits on a raw channel and does not take a scope/cancellation token.
   A caller cancelled while waiting should stop waiting immediately.

4. `CancellationToken::composite()` does not unregister source listeners.
   The comment says benign, but long-lived parent tokens plus many timeout children can retain closures longer than intended. Linked tokens should be disposable.

5. `ProcessHandle::kill()` does not close stdout/stderr or reap with `proc_close()`.
   Process lifecycle should become a supervisor-owned state machine.

6. `PostgresPool::interpolateParams()` is a pragmatic workaround, but regex placeholder replacement will eventually hit SQL string/comment edge cases.
   Treat this as POC-only and hide it behind a future query object or safer driver strategy.

7. `ServiceCatalog::needs()` duplicates dependency facts already present in factory parameters.
   Long term, infer dependencies from factory reflection where possible and make explicit `needs()` the escape hatch, not the normal path.

8. `Trace` is append-only and global per app.
   Once task ids exist, traces should be correlated by run id and scope id.

9. `UsesPool` is declared but not used by dispatch.
   Either wire it into supervisor placement or remove it until placement exists.

10. `Task::of()` rejects non-static closures, which is excellent.
    Extend the same reflection pass to name closures and capture source location.

## Suggested Next POC Milestone

Build a private supervisor ledger without changing much public API.

Acceptance tests:

1. `HasTimeout` applies inside `concurrent`.
2. `Traceable` inside `map` emits task-specific start/end events.
3. `race` cancels losing tasks through task tokens, not only coroutine cancellation.
4. Scope disposal cancels and reports unfinished child tasks.
5. `singleflight` waiter cancellation returns quickly.
6. A failed dispose callback creates a trace event.
7. Worker cancellation marks the worker crashed and the supervisor replaces it before the next dispatch.
8. A diagnostic dump shows task tree, state, wait reason, and cancellation reason.

This milestone moves Phalanx from "Swoole-backed async scope POC" to "supervised async runtime POC."

That distinction is the framework.

## Brass Tacks

The POC is already pointed at something unusual for PHP: centralized, scope-owned async execution with real cancellation and lifecycle semantics.

The next innovation is not more primitives. It is making every primitive report to one supervisor ledger.

Once that exists, the best DX features become natural:

- task trees
- named waits
- precise safety errors
- automatic leak reports
- resource lease tracking
- lock/transaction/pool rejection
- worker health and restart
- compile-time application plans

Other frameworks give users async tools. Phalanx should give users a supervised runtime that explains, rejects, cancels, and cleans up on their behalf.
