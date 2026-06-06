<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Unit;

use Phalanx\Config\ConfigFactory;
use Phalanx\Worker\Dispatch\DispatchStrategy;
use Phalanx\Worker\Worker;
use Phalanx\Worker\ParallelConfig;
use Phalanx\Worker\ParallelDispatch;
use Phalanx\Worker\Supervisor\SupervisorStrategy;
use Phalanx\Service\ServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    #[Test]
    public function workersReturnsConfiguredWorkerDispatch(): void
    {
        self::assertInstanceOf(
            ParallelDispatch::class,
            Worker::workers(ParallelConfig::singleWorker()),
        );
    }

    #[Test]
    public function servicesReturnsServiceBundle(): void
    {
        self::assertInstanceOf(ServiceBundle::class, Worker::services(ParallelConfig::singleWorker()));
    }

    #[Test]
    public function servicesDeclareParallelConfigForAutoRegistration(): void
    {
        self::assertSame([ParallelConfig::class], Worker::services()::configs());
    }

    #[Test]
    public function parallelConfigReadsRuntimeContext(): void
    {
        $config = ConfigFactory::fromContext([
            ParallelConfig::CONTEXT_AGENTS => 8,
            ParallelConfig::CONTEXT_MAILBOX_LIMIT => 42,
            ParallelConfig::CONTEXT_DISPATCHER => 'round_robin',
            ParallelConfig::CONTEXT_SUPERVISION => 'stop_all',
            ParallelConfig::CONTEXT_WORKER_SCRIPT => 'worker.php',
            ParallelConfig::CONTEXT_AUTOLOAD_PATH => 'vendor/autoload.php',
        ])->hydrate(ParallelConfig::class);

        self::assertInstanceOf(ParallelConfig::class, $config);
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
