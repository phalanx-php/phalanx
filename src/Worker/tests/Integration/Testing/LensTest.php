<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Integration\Testing;

use Phalanx\Runtime\Identity\RuntimeResourceSid;
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

        try {
            $app->worker->run(new TaskThatThrows('Intentional failure'));

            self::fail('Expected worker task exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('Intentional failure', $e->getMessage());
        }

        $app->runtime
            ->assertNoLiveResources(RuntimeResourceSid::Scope)
            ->assertNoLiveTasks();
    }
}
