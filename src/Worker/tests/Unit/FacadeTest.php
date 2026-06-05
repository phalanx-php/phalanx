<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Unit;

use Phalanx\Boot\AppContext;
use Phalanx\Worker\Dispatch\DispatchStrategy;
use Phalanx\Worker\Facade;
use Phalanx\Worker\ParallelConfig;
use Phalanx\Worker\ParallelDispatch;
use Phalanx\Worker\Supervisor\SupervisorStrategy;
use Phalanx\Service\ServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FacadeTest extends TestCase
{
    #[Test]
    public function workersReturnsConfiguredWorkerDispatch(): void
    {
        self::assertInstanceOf(
            ParallelDispatch::class,
            Facade::workers(ParallelConfig::singleWorker()),
        );
    }

    #[Test]
    public function servicesReturnsServiceBundle(): void
    {
        self::assertInstanceOf(ServiceBundle::class, Facade::services(ParallelConfig::singleWorker()));
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

    #[Test]
    public function parallelConfigContextKeysUseWorkerVocabulary(): void
    {
        self::assertSame('WORKER_AGENTS', ParallelConfig::CONTEXT_AGENTS);
        self::assertSame('WORKER_MAILBOX_LIMIT', ParallelConfig::CONTEXT_MAILBOX_LIMIT);
        self::assertSame('WORKER_DISPATCHER', ParallelConfig::CONTEXT_DISPATCHER);
        self::assertSame('WORKER_SUPERVISION', ParallelConfig::CONTEXT_SUPERVISION);
        self::assertSame('WORKER_SCRIPT', ParallelConfig::CONTEXT_WORKER_SCRIPT);
        self::assertSame('WORKER_AUTOLOAD_PATH', ParallelConfig::CONTEXT_AUTOLOAD_PATH);
    }
}
