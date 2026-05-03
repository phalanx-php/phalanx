<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\CoroutineTestCase;
use RuntimeException;

final class TaskExecutorVariadicTest extends CoroutineTestCase
{
    public function testSettlePreservesKeysForValuesAndErrors(): void
    {
        $this->runScoped(static function (ExecutionScope $scope): void {
            $bag = $scope->settle(...[
                'ok.task' => Task::of(static fn(): string => 'done'),
                'bad-task' => Task::of(static function (): never {
                    throw new RuntimeException('failed');
                }),
            ]);

            self::assertSame(['ok.task' => 'done'], $bag->values);
            self::assertSame(['bad-task'], $bag->errKeys);
            self::assertSame('failed', $bag->errors['bad-task']->getMessage());
        });
    }

    public function testEmptyTaskListSemanticsArePreserved(): void
    {
        $this->runScoped(static function (ExecutionScope $scope): void {
            self::assertSame([], $scope->concurrent());
            self::assertSame([], $scope->series());
            self::assertNull($scope->waterfall());
            self::assertCount(0, $scope->settle());

            $raceFailure = null;
            $anyFailure = null;

            try {
                $scope->race();
            } catch (RuntimeException $e) {
                $raceFailure = $e;
            }

            try {
                $scope->any();
            } catch (RuntimeException $e) {
                $anyFailure = $e;
            }

            self::assertSame('race(): empty task list', $raceFailure?->getMessage());
            self::assertSame('any(): empty task list', $anyFailure?->getMessage());
        });
    }
}
