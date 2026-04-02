<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit;

use Phalanx\Filesystem\FilePool;
use PHPUnit\Framework\TestCase;

final class FilePoolTest extends TestCase
{
    public function test_acquire_within_limit(): void
    {
        $pool = new FilePool(maxOpen: 3);
        $scope = $this->createStub(\Phalanx\Suspendable::class);

        $pool->acquire($scope);
        $pool->acquire($scope);
        $pool->acquire($scope);

        $this->assertSame(3, $pool->activeCount);
    }

    public function test_release_decrements(): void
    {
        $pool = new FilePool(maxOpen: 10);
        $scope = $this->createStub(\Phalanx\Suspendable::class);

        $pool->acquire($scope);
        $pool->acquire($scope);
        $this->assertSame(2, $pool->activeCount);

        $pool->release();
        $this->assertSame(1, $pool->activeCount);
    }

    public function test_waiting_count(): void
    {
        $pool = new FilePool(maxOpen: 1);

        $this->assertSame(0, $pool->waitingCount);
    }
}
