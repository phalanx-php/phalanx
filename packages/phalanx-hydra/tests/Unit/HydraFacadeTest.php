<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Unit;

use Phalanx\Boot\AppContext;
use Phalanx\Hydra\Dispatch\DispatchStrategy;
use Phalanx\Hydra\Hydra;
use Phalanx\Hydra\ParallelConfig;
use Phalanx\Hydra\ParallelWorkerDispatch;
use Phalanx\Hydra\Supervisor\SupervisorStrategy;
use Phalanx\Service\ServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HydraFacadeTest extends TestCase
{
    #[Test]
    public function workersReturnsConfiguredWorkerDispatch(): void
    {
        self::assertInstanceOf(
            ParallelWorkerDispatch::class,
            Hydra::workers(ParallelConfig::singleWorker()),
        );
    }

    #[Test]
    public function servicesReturnsServiceBundle(): void
    {
        self::assertInstanceOf(ServiceBundle::class, Hydra::services(ParallelConfig::singleWorker()));
    }

    #[Test]
    public function parallelConfigReadsRuntimeContext(): void
    {
        $config = ParallelConfig::fromContext(new AppContext([
            ParallelConfig::CONTEXT_AGENTS => 8,
            ParallelConfig::CONTEXT_MAILBOX_LIMIT => 42,
            ParallelConfig::CONTEXT_DISPATCHER => 'round_robin',
            ParallelConfig::CONTEXT_SUPERVISION => 'stop_all',
            ParallelConfig::CONTEXT_WORKER_SCRIPT => 'worker.php',
            ParallelConfig::CONTEXT_AUTOLOAD_PATH => 'vendor/autoload.php',
        ]));

        self::assertSame(8, $config->agents);
        self::assertSame(42, $config->mailboxLimit);
        self::assertSame(DispatchStrategy::RoundRobin, $config->dispatcher);
        self::assertSame(SupervisorStrategy::StopAll, $config->supervision);
        self::assertSame('worker.php', $config->workerScript);
        self::assertSame('vendor/autoload.php', $config->autoloadPath);
    }
}
