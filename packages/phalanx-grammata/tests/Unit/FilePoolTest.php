<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Tests\Unit;

use Phalanx\Application;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\FilePool;
use Phalanx\Grammata\Grammata;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Suspendable;
use Phalanx\Styx\Channel;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

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
    public function releaseWithoutAcquireFailsClearly(): void
    {
        $pool = new FilePool(maxOpen: 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no active file slot');

        $pool->release();
    }

    #[Test]
    public function maxOpenMustBePositive(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('maxOpen >= 1');

        new FilePool(maxOpen: 0);
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

    #[Test]
    public function cancelledWaitingAcquireIsRemoved(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $pool = new FilePool(maxOpen: 1);
            $pool->acquire($scope);

            try {
                $thrown = null;
                try {
                    $scope->timeout(
                        0.01,
                        Task::of(static fn(ExecutionScope $child): mixed => $pool->acquire($child)),
                    );
                } catch (Cancelled $e) {
                    $thrown = $e;
                }

                self::assertNotNull($thrown);
                self::assertSame(1, $pool->activeCount);
                self::assertSame(0, $pool->waitingCount);
            } finally {
                $pool->release();
            }

            self::assertSame(0, $pool->activeCount);
        });
    }

    #[Test]
    public function readStreamReleasesSlotAfterConsumption(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phalanx-stream-read-');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, 'streamed');

        try {
            $result = Application::starting()
                ->providers(Grammata::services(maxOpen: 1))
                ->run(Task::named(
                    'test.grammata.stream.read-release',
                    static function (ExecutionScope $scope) use ($tmpFile): array {
                        $pool = $scope->service(FilePool::class);
                        $stream = Grammata::files($scope)->readStream($tmpFile);
                        $chunks = [];

                        foreach ($stream($scope) as $chunk) {
                            $chunks[] = $chunk;
                        }

                        return [
                            'contents' => implode('', $chunks),
                            'active' => $pool->activeCount,
                        ];
                    },
                ));

            self::assertSame(['contents' => 'streamed', 'active' => 0], $result);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function readStreamReleasesSlotAfterOpenFailure(): void
    {
        $result = Application::starting()
            ->providers(Grammata::services(maxOpen: 1))
            ->run(Task::named(
                'test.grammata.stream.read-open-failure',
                static function (ExecutionScope $scope): array {
                    $pool = $scope->service(FilePool::class);
                    $stream = Grammata::files($scope)->readStream('/missing/phalanx-stream-read.txt');
                    $threw = false;

                    try {
                        foreach ($stream($scope) as $_chunk) {
                        }
                    } catch (FilesystemException) {
                        $threw = true;
                    }

                    return [
                        'threw' => $threw,
                        'active' => $pool->activeCount,
                    ];
                },
            ));

        self::assertSame(['threw' => true, 'active' => 0], $result);
    }
}
