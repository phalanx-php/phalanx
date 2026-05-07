<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Tests\Unit;

use Phalanx\Hydra\Hydra;
use Phalanx\Hydra\ParallelConfig;
use Phalanx\Hydra\ParallelWorkerDispatch;
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
}
