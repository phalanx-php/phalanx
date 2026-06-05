<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Worker\Dispatch\LeastMailboxDispatcher;
use Phalanx\Worker\Dispatch\RoundRobinDispatcher;
use Phalanx\Worker\WorkerDispatch;
use Symfony\Component\Process\Pipes\AbstractPipes;
use Symfony\Component\Process\Pipes\UnixPipes;
use Symfony\Component\Process\Pipes\WindowsPipes;
use Symfony\Component\Process\Process;

final class Bundle extends ServiceBundle
{
    public function __construct(
        private ?ParallelConfig $config = null,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        self::prewarmHookSafeClasses();

        $parallelConfig = $this->config ?? ParallelConfig::fromContext($context);
        $services->singleton(ParallelConfig::class)
            ->factory(static fn(): ParallelConfig => $parallelConfig);

        $services->singleton(WorkerDispatch::class)
            ->needs(ParallelConfig::class)
            ->factory(static fn(ParallelConfig $config): WorkerDispatch => new ParallelDispatch($config))
            ->onShutdown(static function (WorkerDispatch $dispatch): void {
                $dispatch->shutdown();
            });
    }

    /**
     * Force-load classes that fail to resolve when first referenced from inside
     * a Swoole coroutine with SWOOLE_HOOK_FILE active. The interaction is:
     * SWOOLE_HOOK_FILE converts `include` to coroutine-aware I/O; Symfony's
     * DebugClassLoader (enabled in dev mode by symfony/runtime) wraps autoload
     * and calls `include` for each class in an inheritance chain. Inside a
     * coroutine the fiber can suspend mid-include, leaving PHP's class table
     * inconsistent and surfacing "Class not found" for classes that ARE
     * PSR-4 autoloadable in the parent process.
     *
     * services() runs at compile() time, before the reactor starts and before
     * hooks enable. Loading these classes here resolves them through the
     * standard Composer autoloader, not the hooked file layer.
     *
     * Two class families are pre-warmed:
     *   - Symfony Process hierarchy used by worker subprocess pipes.
     *   - Worker dispatcher implementations (cold-path arm of the match in
     *     Supervisor::createDispatcher() fails to resolve at lazy
     *     reference time even though both classes are autoloadable).
     */
    private static function prewarmHookSafeClasses(): void
    {
        \class_exists(AbstractPipes::class, true);
        \class_exists(UnixPipes::class, true);
        \class_exists(WindowsPipes::class, true);
        \class_exists(Process::class, true);
        \class_exists(RoundRobinDispatcher::class, true);
        \class_exists(LeastMailboxDispatcher::class, true);
    }
}
