<?php

declare(strict_types=1);

namespace Phalanx\Pool;

use Closure;
use OpenSwoole\Core\Coroutine\Pool\ClientPool;
use Phalanx\Runtime\CoroutineRuntime;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\PoolLease;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Aegis-managed connection pool primitive.
 *
 * Composes `OpenSwoole\Core\Coroutine\Pool\ClientPool` (the OpenSwoole
 * core pool with Channel-backed checkout, optional heartbeats, and
 * reconnection) and adds Phalanx's lease tracking, supervised acquire
 * suspension, and starvation diagnostics.
 *
 * Construction:
 *
 *   new ManagedPool(
 *       domain: 'postgres/main',
 *       factoryClass: PostgresClientFactory::class,
 *       config: $pgConfig,
 *       trace: $trace,
 *       size: 16,
 *   );
 *
 * The factory class implements {@see ManagedPoolFactory} (a static
 * `make($config): ManagedPoolClient` contract) and is the same shape OpenSwoole's
 * core ClientPool consumes — no shim or adapter required.
 *
 * Consumer pattern:
 *
 *   $rows = $pool->use($scope, static fn(PostgresClient $c): array => $c->query(...));
 *
 * `use()` guarantees release even on exception. Direct `acquire()` /
 * `release()` are exposed for callers that need finer control, with
 * try/finally discipline expected at the call site.
 *
 * Pool lifecycle is owned by the bundle that registered it; ManagedPool
 * does not auto-dispose on scope teardown because pools are typically
 * singleton resources, not request-scoped. Service-bundle `onShutdown`
 * callbacks should call `$pool->close()`.
 */
final class ManagedPool
{
    public int $size {
        get => $this->poolSize;
    }

    private readonly int $poolSize;

    private readonly ClientPool $clientPool;

    /** @var array<string, array{client: ManagedPoolClient, supervisor: ?Supervisor, run: ?TaskRun}> */
    private array $checkedOut = [];

    private bool $closed = false;

    /**
     * @param class-string $factoryClass class implementing {@see ManagedPoolFactory}
     */
    public function __construct(
        public readonly string $domain,
        string $factoryClass,
        mixed $config,
        private readonly Trace $trace,
        int $size = ClientPool::DEFAULT_SIZE,
        private readonly float $starvationThresholdMs = 250.0,
        bool $heartbeat = false,
    ) {
        $this->poolSize = $size;
        $this->clientPool = new ClientPool($factoryClass, $config, $size, $heartbeat);
    }

    public function acquire(Suspendable $scope, float $timeout = -1.0): PoolLease
    {
        if ($this->closed) {
            throw new RuntimeException("ManagedPool({$this->domain})::acquire(): pool is closed");
        }

        $start = microtime(true);
        $domain = $this->domain;
        $pool = $this->clientPool;

        try {
            $client = $scope->call(
                static fn(): mixed => $pool->get($timeout),
                WaitReason::custom("pool.acquire {$domain}"),
            );
        } catch (Throwable $e) {
            $this->compensateFailedCheckout();
            throw $e;
        }

        if (!$client instanceof ManagedPoolClient) {
            $this->compensateFailedCheckout();
            throw new RuntimeException(
                "ManagedPool({$this->domain})::acquire(): pool returned no managed client (timeout or closed)",
            );
        }

        $waitedMs = (microtime(true) - $start) * 1000.0;
        if ($waitedMs > $this->starvationThresholdMs) {
            $this->trace->log(
                TraceType::Lifecycle,
                'PHX-POOL-001',
                [
                    'domain' => $this->domain,
                    'wait_ms' => $waitedMs,
                    'threshold_ms' => $this->starvationThresholdMs,
                    'detail' => 'pool acquire wait exceeded threshold; investigate saturation or pool sizing',
                ],
            );
        }

        $connectionId = 'c' . spl_object_id($client);
        $lease = PoolLease::open($this->domain, $connectionId);
        [$supervisor, $run] = $this->currentRunContext($scope);
        $this->checkedOut[$connectionId] = [
            'client' => $client,
            'supervisor' => $supervisor,
            'run' => $run,
        ];

        try {
            if ($supervisor !== null && $run !== null) {
                $supervisor->registerLease($run, $lease);
            }
        } catch (Throwable $e) {
            unset($this->checkedOut[$connectionId]);
            $this->clientPool->put($client);
            throw $e;
        }

        return $lease;
    }

    public function release(PoolLease $lease): void
    {
        $checkout = $this->checkedOut[$lease->key] ?? null;
        if ($checkout === null) {
            $this->trace->log(
                TraceType::Defer,
                'PHX-POOL-003',
                [
                    'domain' => $this->domain,
                    'key' => $lease->key,
                    'detail' => 'pool release for unknown lease key (double-release?)',
                ],
            );
            return;
        }
        unset($this->checkedOut[$lease->key]);

        try {
            if ($checkout['supervisor'] !== null && $checkout['run'] !== null) {
                $checkout['supervisor']->releaseLease($checkout['run'], $lease);
            }
        } finally {
            $this->clientPool->put($checkout['client']);
        }
    }

    /**
     * @template T
     * @param Closure(object): T $work
     * @return T
     */
    public function use(Suspendable $scope, Closure $work, float $timeout = -1.0): mixed
    {
        $lease = $this->acquire($scope, $timeout);
        try {
            $client = $this->checkedOut[$lease->key]['client'];
            return $work($client);
        } finally {
            $this->release($lease);
        }
    }

    public function close(): void
    {
        CoroutineRuntime::run(
            RuntimePolicy::phalanxManaged(),
            function (): void {
                $this->closeInsideCoroutine();
            },
        );
    }

    private function closeInsideCoroutine(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->clientPool->close();
        $this->checkedOut = [];
    }

    /** @return array{?Supervisor, ?TaskRun} */
    private function currentRunContext(Suspendable $scope): array
    {
        if (!$scope instanceof ExecutionLifecycleScope) {
            return [null, null];
        }

        return [$scope->supervisor(), $scope->currentTaskRun()];
    }

    private function compensateFailedCheckout(): void
    {
        $property = new ReflectionProperty(ClientPool::class, 'active');
        $active = $property->getValue($this->clientPool);

        if (is_int($active) && $active > 0) {
            $property->setValue($this->clientPool, $active - 1);
        }
    }
}
