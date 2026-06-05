<?php

declare(strict_types=1);

namespace Phalanx\Worker\Pool;

use Closure;
use Swoole\Process\Pool as ProcessPool;

use function Swoole\Coroutine\run as swoole_coroutine_run;

/**
 * Runtime-managed worker pool primitive.
 *
 * Composes `Swoole\Process\Pool` directly with a typed Phalanx-native
 * facade. The existing `Phalanx\Worker\Agent\Worker` request/response IPC
 * serves service-call workloads; this Pool targets the simpler
 * "spawn N processes that run a function and stay alive under a
 * supervisor" use case (plx-ops background workers, scheduled job pools,
 * fan-out batch processors).
 *
 * The C-level supervisor restarts crashed workers automatically; the
 * pool propagates SIGTERM/SIGINT to children via Process\Pool's built-in
 * signal handling. No PHP-level child-tracking is needed.
 *
 * Lifecycle: construct, add() one or more worker functions, then start().
 * `start()` blocks the calling process — workers run until the master
 * receives a termination signal. For embedding inside an Runtime bundle
 * with onShutdown registration, build the Pool from a service
 * factory and call start() from the application entry point.
 */
final class Pool
{
    private(set) int $workerCount = 0;

    /** @var list<array{0: Closure, 1: bool}> */
    private array $factories = [];

    public function __construct(
        private int $ipcType = SWOOLE_IPC_NONE,
        private int $msgQueueKey = 0,
    ) {
    }

    /**
     * Convenience helper for the most common configuration: one function,
     * N coroutine-enabled workers, no IPC.
     *
     * @param Closure(ProcessPool, int): void $func
     */
    public static function ofSize(int $workerNum, Closure $func): self
    {
        $pool = new self(SWOOLE_IPC_NONE, 0);
        $pool->addBatch($workerNum, $func, enableCoroutine: true);

        return $pool;
    }

    public static function eventWorkerStart(): string
    {
        $constant = 'Swoole\\Constant::EVENT_WORKER_START';

        return defined($constant) ? (string) constant($constant) : 'workerStart';
    }

    /**
     * Add a single worker function. The function receives the `Pool` and
     * its assigned worker id at runtime. When `$enableCoroutine` is true,
     * the worker body runs inside `Coroutine::run`, so coroutine-aware
     * code (Runtime scopes, managed clients) works inside it.
     *
     * @param Closure(ProcessPool, int): void $func
     */
    public function add(Closure $func, bool $enableCoroutine = true): self
    {
        $this->factories[] = [$func, $enableCoroutine];
        $this->workerCount = count($this->factories);

        return $this;
    }

    /**
     * Add `$workerNum` workers running the same function. Equivalent to
     * calling `add()` `$workerNum` times.
     *
     * @param Closure(ProcessPool, int): void $func
     */
    public function addBatch(int $workerNum, Closure $func, bool $enableCoroutine = true): self
    {
        for ($i = 0; $i < $workerNum; $i++) {
            $this->factories[] = [$func, $enableCoroutine];
        }
        $this->workerCount = count($this->factories);

        return $this;
    }

    /**
     * Start the pool. Blocks the calling process until the master is
     * signalled to terminate. Workers crashing are restarted by the
     * underlying `Swoole\Process\Pool`.
     */
    public function start(): void
    {
        $pool = new ProcessPool(count($this->factories), $this->ipcType, $this->msgQueueKey);
        $factories = $this->factories;

        $pool->on(self::eventWorkerStart(), static function (ProcessPool $pool, int $workerId) use ($factories): void {
            if (!isset($factories[$workerId])) {
                return;
            }

            [$func, $enableCoroutine] = $factories[$workerId];

            if ($enableCoroutine) {
                // Direct coroutine run — child process has no Runtime boot
                swoole_coroutine_run(static function () use ($func, $pool, $workerId): void {
                    $func($pool, $workerId);
                });
            } else {
                $func($pool, $workerId);
            }
        });

        $pool->start();
    }
}
