<?php

declare(strict_types=1);

namespace Phalanx\Hydra;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Worker\WorkerDispatch;
use Symfony\Component\Process\Pipes\AbstractPipes;
use Symfony\Component\Process\Pipes\UnixPipes;
use Symfony\Component\Process\Pipes\WindowsPipes;
use Symfony\Component\Process\Process;

final class HydraServiceBundle extends ServiceBundle
{
    public function __construct(
        private ?ParallelConfig $config = null,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        // Pre-warm the Symfony Process class hierarchy before any coroutine context
        // is active. OpenSwoole's SWOOLE_HOOK_FILE converts `include` calls to
        // coroutine-aware operations; Symfony's DebugClassLoader (enabled in dev
        // mode by symfony/runtime) wraps autoload and calls `include` for each class
        // in an inheritance chain. When these two interact inside a coroutine, the
        // fiber context can suspend mid-include, leaving the PHP class resolution in
        // an inconsistent state — causing "Class not found" for classes that ARE
        // autoloadable in the parent process.
        //
        // services() runs at compile() time, before CoroutineRuntime::run() starts
        // the reactor and enables hooks. Loading these classes here ensures they are
        // resolved by the standard Composer autoloader, not the hooked file layer.
        \class_exists(AbstractPipes::class, true);
        \class_exists(UnixPipes::class, true);
        \class_exists(WindowsPipes::class, true);
        \class_exists(Process::class, true);

        $parallelConfig = $this->config ?? ParallelConfig::fromContext($context);

        $services->config(ParallelConfig::class, static fn(): ParallelConfig => $parallelConfig);
        $services->singleton(WorkerDispatch::class)
            ->needs(ParallelConfig::class)
            ->factory(static fn(ParallelConfig $config): WorkerDispatch => new ParallelWorkerDispatch($config))
            ->onShutdown(static function (WorkerDispatch $dispatch): void {
                $dispatch->shutdown();
            });
    }
}
