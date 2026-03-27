# Phalanx Parallel

Offload CPU-heavy work to supervised child processes. Tasks serialize, cross process boundaries via IPC, execute in isolated workers, and return results--all through a single `$scope->inWorker()` call.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [How Workers Work](#how-workers-work)
- [Configuration](#configuration)
- [Dispatch Strategies](#dispatch-strategies)
- [Supervision & Recovery](#supervision--recovery)
- [Service Proxying](#service-proxying)
- [Bounded Parallel Map](#bounded-parallel-map)

## Installation

```bash
composer require phalanx/parallel
```

Requires PHP 8.4+ and `ext-pcntl`.

## Quick Start

```php
<?php

use Phalanx\Application;
use Phalanx\Parallel\ParallelConfig;

$app = Application::starting()
    ->withWorkerDispatch(ParallelConfig::default()->workerDispatchFactory())
    ->providers(new AppBundle())
    ->compile();

$app->startup();
$scope = $app->createScope();

// Offload image processing to a worker
$result = $scope->inWorker(new ProcessImage($path, width: 800, height: 600));

$scope->dispose();
$app->shutdown();
```

The task must be an invokable class with serializable constructor arguments. Closures cannot cross process boundaries--this is enforced at dispatch time.

```php
<?php

final readonly class ProcessImage implements Scopeable
{
    public function __construct(
        private string $path,
        private int $width,
        private int $height,
    ) {}

    public function __invoke(Scope $scope): ProcessedImage
    {
        $image = $scope->service(ImageProcessor::class);

        return $image->resize($this->path, $this->width, $this->height);
    }
}
```

Constructor args serialize to JSON, travel over stdin/stdout to the worker process, reconstruct the task, execute it, and return the result the same way back.

## How Workers Work

The supervisor starts N worker processes at boot. Each worker is an independent PHP process with its own service graph, event loop, and memory space. Communication uses a JSON-newline protocol over stdin/stdout--one JSON object per line, no framing ambiguity.

Each worker moves through a state machine:

**Idle** -- waiting for work. **Processing** -- executing a task. **Draining** -- finishing in-flight work before shutdown. **Crashed** -- process died unexpectedly, supervisor handles restart.

Every worker has a **Mailbox**--a bounded queue that accepts dispatched tasks. When a mailbox fills, the dispatcher throws `OverflowException` rather than silently buffering. This is backpressure by design: the caller knows immediately when the pool is saturated.

```php
<?php

// Tasks queue in the worker's mailbox until the worker picks them up
$scope->inWorker(new CompressVideo($file));    // queued
$scope->inWorker(new CompressVideo($file2));   // queued
$scope->inWorker(new CompressVideo($file3));   // queued -- all executing in parallel across worker processes
```

## Configuration

`ParallelConfig` controls pool behavior. Named constructors handle common patterns:

```php
<?php

use Phalanx\Parallel\ParallelConfig;

// 4 workers, least-mailbox dispatch, restart on crash
ParallelConfig::default();

// Single worker for sequential offloading
ParallelConfig::singleWorker();

// One worker per CPU core (auto-detected via sysctl/nproc)
ParallelConfig::cpuBound();

// Full control
new ParallelConfig(
    agents: 8,
    mailboxLimit: 200,
    dispatcher: DispatchStrategy::RoundRobin,
    supervision: SupervisorStrategy::RestartOnCrash,
);
```

Wire it into the application through the factory method:

```php
<?php

$app = Application::starting()
    ->withWorkerDispatch(ParallelConfig::cpuBound()->workerDispatchFactory())
    ->compile();
```

The factory is a closure that receives the `ServiceGraph` and `LazySingleton` container at compile time, so worker processes get access to the same service definitions as the parent.

## Dispatch Strategies

Two built-in strategies determine which worker receives the next task:

**LeastMailbox** (default) -- sends work to the worker with the smallest mailbox queue. Naturally balances load when tasks have variable execution times.

**RoundRobin** -- cycles through workers sequentially. Predictable distribution when tasks are roughly equal cost.

```php
<?php

use Phalanx\Parallel\Dispatch\DispatchStrategy;

new ParallelConfig(
    agents: 4,
    dispatcher: DispatchStrategy::LeastMailbox,  // default
);

new ParallelConfig(
    agents: 4,
    dispatcher: DispatchStrategy::RoundRobin,
);
```

## Supervision & Recovery

`WorkerSupervisor` monitors every worker process. When a worker crashes, the supervisor:

1. Rejects all pending tasks in that worker's mailbox with the crash reason
2. Tracks restart history per worker
3. Restarts the worker with exponential backoff
4. Resumes dispatching to the recovered worker

This happens automatically with `SupervisorStrategy::RestartOnCrash`. The pool stays operational even when individual workers fail--callers see a rejected promise for the specific task that was in-flight, not a pool-wide failure.

On shutdown, workers enter the **Draining** state: they finish their current task, reject any remaining mailbox items, and exit cleanly. The supervisor waits for all workers to drain before the parent process continues teardown.

## Service Proxying

Worker processes run in separate memory spaces, but they still need access to application services. `ParentServiceProxy` and `ServiceProxy` bridge this gap:

The parent process registers a service handler on each worker's IPC channel. When a worker calls `$scope->service(SomeService::class)`, the `ServiceProxy` in the worker serializes the call, sends it to the parent over IPC, the parent resolves the service and executes the method, then returns the serialized result.

This means worker tasks can use the same `Scope` interface without knowing they're in a child process. Services that hold connections (database pools, Redis clients) stay in the parent--workers proxy through to them.

## Bounded Parallel Map

Combine `inWorker` with core's `map()` to process collections across the worker pool with bounded concurrency:

```php
<?php

// Process 10,000 images: 8 parallel workers, 20 concurrent dispatches
$results = $scope->map(
    $imagePaths,
    static fn(string $path) => new ProcessImage($path, width: 800, height: 600),
    limit: 20,
);
```

Each iteration calls `$scope->inWorker()` under the hood. The `limit` parameter controls how many tasks are in-flight simultaneously--the worker pool handles the actual parallelism, while `map` handles the concurrency bound.
