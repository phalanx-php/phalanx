<?php

declare(strict_types=1);

namespace Phalanx\Hydra\WorkerPool;

use Closure;
use OpenSwoole\Constant;
use OpenSwoole\Core\Process\Manager;

/**
 * Aegis-managed worker pool primitive.
 *
 * Wraps `OpenSwoole\Core\Process\Manager` (which itself composes
 * `OpenSwoole\Process\Pool`) with a typed Phalanx-native facade. The
 * existing `Phalanx\Hydra\Agent\Worker` (React-based request/response
 * IPC) remains unchanged for service-call workloads; this WorkerPool
 * targets the simpler "spawn N processes that run a function and stay
 * alive under a supervisor" use case (plx-ops background workers,
 * scheduled job pools, fan-out batch processors).
 *
 * The C-level supervisor restarts crashed workers automatically; the
 * pool propagates SIGTERM/SIGINT to children via Process\Pool's built-in
 * signal handling. No PHP-level child-tracking is needed.
 *
 * Lifecycle: construct, add() one or more worker functions, then start().
 * `start()` blocks the calling process — workers run until the master
 * receives a termination signal. For embedding inside an Aegis bundle
 * with onShutdown registration, build the WorkerPool from a service
 * factory and call start() from the application entry point.
 */
final class WorkerPool
{
    public int $workerCount {
        get => count($this->factories);
    }

    private readonly Manager $manager;

    /** @var list<array{0: Closure, 1: bool}> */
    private array $factories = [];

    public function __construct(int $ipcType = SWOOLE_IPC_NONE, int $msgQueueKey = 0)
    {
        $this->manager = new Manager($ipcType, $msgQueueKey);
    }

    /**
     * Add a single worker function. The function receives the `Pool` and
     * its assigned worker id at runtime. When `$enableCoroutine` is true,
     * OpenSwoole wraps the worker body in `Coroutine::run`, so coroutine-
     * aware code (Aegis scopes, OpenSwoole clients) works inside it.
     *
     * @param Closure(\OpenSwoole\Process\Pool, int): void $func
     */
    public function add(Closure $func, bool $enableCoroutine = true): self
    {
        $this->factories[] = [$func, $enableCoroutine];
        $this->manager->add($func, $enableCoroutine);
        return $this;
    }

    /**
     * Add `$workerNum` workers running the same function. Equivalent to
     * calling `add()` `$workerNum` times.
     *
     * @param Closure(\OpenSwoole\Process\Pool, int): void $func
     */
    public function addBatch(int $workerNum, Closure $func, bool $enableCoroutine = true): self
    {
        for ($i = 0; $i < $workerNum; $i++) {
            $this->factories[] = [$func, $enableCoroutine];
        }
        $this->manager->addBatch($workerNum, $func, $enableCoroutine);
        return $this;
    }

    /**
     * Start the pool. Blocks the calling process until the master is
     * signalled to terminate. Workers crashing are restarted by the
     * underlying `OpenSwoole\Process\Pool`.
     */
    public function start(): void
    {
        $this->manager->start();
    }

    /**
     * Convenience helper for the most common configuration: one function,
     * N coroutine-enabled workers, no IPC.
     *
     * @param Closure(\OpenSwoole\Process\Pool, int): void $func
     */
    public static function ofSize(int $workerNum, Closure $func): self
    {
        $pool = new self(SWOOLE_IPC_NONE, 0);
        $pool->addBatch($workerNum, $func, enableCoroutine: true);
        return $pool;
    }

    public static function eventWorkerStart(): string
    {
        return Constant::EVENT_WORKER_START;
    }
}
