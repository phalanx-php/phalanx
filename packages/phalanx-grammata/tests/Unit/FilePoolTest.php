<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Tests\Unit;

use Phalanx\Grammata\FilePool;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Suspendable;
use Phalanx\Styx\Channel;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FilePoolTest extends PhalanxTestCase
{
    #[Test]
    public function acquireWithinLimit(): void
    {
        $pool = new FilePool(maxOpen: 3);
        $scope = $this->createStub(Suspendable::class);

        $pool->acquire($scope);
        $pool->acquire($scope);
        $pool->acquire($scope);

        $this->assertSame(3, $pool->activeCount);
    }

    #[Test]
    public function releaseDecrements(): void
    {
        $pool = new FilePool(maxOpen: 10);
        $scope = $this->createStub(Suspendable::class);

        $pool->acquire($scope);
        $pool->acquire($scope);
        $this->assertSame(2, $pool->activeCount);

        $pool->release();
        $this->assertSame(1, $pool->activeCount);
    }

    #[Test]
    public function waitingAcquireResumesWhenSlotIsReleased(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $pool = new FilePool(maxOpen: 1);
            $started = new Channel(bufferSize: 1);
            $resumed = new Channel(bufferSize: 1);
            $finish = new Channel(bufferSize: 1);
            $released = new Channel(bufferSize: 1);

            $pool->acquire($scope);

            $scope->go(static function (ExecutionScope $childScope) use (
                $pool,
                $finish,
                $started,
                $resumed,
                $released,
            ): void {
                $started->emit(true);
                $pool->acquire($childScope);
                $resumed->emit(true);
                $finish->next();
                $pool->release();
                $released->emit(true);
            });

            $started->next();

            self::assertSame(1, $pool->activeCount);
            self::assertSame(1, $pool->waitingCount);

            $pool->release();
            $resumed->next();

            self::assertSame(1, $pool->activeCount);
            self::assertSame(0, $pool->waitingCount);

            $finish->emit(true);
            $released->next();

            self::assertSame(0, $pool->activeCount);
        });
    }

    #[Test]
    public function waitingCount(): void
    {
        $pool = new FilePool(maxOpen: 1);

        $this->assertSame(0, $pool->waitingCount);
    }
}
