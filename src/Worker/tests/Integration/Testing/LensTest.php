<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Integration\Testing;

use Phalanx\Runtime\Tests\Support\Fixtures\AddNumbers;
use Phalanx\Runtime\Tests\Support\Fixtures\TaskThatThrows;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Worker\Bundle;
use Phalanx\Worker\ParallelConfig;
use Phalanx\Worker\Testing\Lens;
use PHPUnit\Framework\Attributes\Test;

final class LensTest extends PhalanxTestCase
{
    #[Test]
    public function workerLensDispatchesWorkerTaskAndAssertsCleanup(): void
    {
        $app = $this->testApp([], new Bundle(new ParallelConfig(agents: 1)));

        self::assertInstanceOf(Lens::class, $app->worker);

        $app->worker
            ->run(new AddNumbers(2, 3))
            ->assertValueSame(5)
            ->assertNoLiveRuntimeScopes()
            ->assertNoLiveTasks();
    }

    #[Test]
    public function workerLensPropagatesTaskExceptions(): void
    {
        $app = $this->testApp([], new Bundle(new ParallelConfig(agents: 1)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Intentional failure');

        $app->worker->run(new TaskThatThrows('Intentional failure'));
    }
}
