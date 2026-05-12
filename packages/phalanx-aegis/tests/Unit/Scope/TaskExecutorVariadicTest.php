<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use RuntimeException;

final class TaskExecutorVariadicTest extends PhalanxTestCase
{
    public function testSettlePreservesKeysForValuesAndErrors(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $bag = $scope->settle(
                okTask: Task::of(static fn(): string => 'done'),
                badTask: Task::of(static function (): never {
                    throw new RuntimeException('failed');
                }),
            );

            self::assertSame(['okTask' => 'done'], $bag->values);
            self::assertSame(['badTask'], $bag->errKeys);
            self::assertSame('failed', $bag->errors['badTask']->getMessage());
        });
    }

    public function testSettlePreservesExoticKeysViaArraySpread(): void
    {
        // Named args require valid PHP identifiers, but variable-array spread is
        // the sanctioned escape hatch for runtime-built task maps. The receiving
        // foreach must still preserve exotic string keys end-to-end.
        $this->scope->run(static function (ExecutionScope $scope): void {
            $tasks = [
                'k.dot'  => Task::of(static fn(): string => 'ok'),
                'k-dash' => Task::of(static function (): never {
                    throw new RuntimeException('boom');
                }),
            ];

            $bag = $scope->settle(...$tasks);

            self::assertSame(['k.dot' => 'ok'], $bag->values);
            self::assertSame(['k-dash'], $bag->errKeys);
            self::assertSame('boom', $bag->errors['k-dash']->getMessage());
        });
    }

    public function testEmptyTaskListSemanticsArePreserved(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
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
