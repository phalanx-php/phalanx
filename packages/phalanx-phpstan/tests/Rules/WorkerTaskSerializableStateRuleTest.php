<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Worker\WorkerTaskSerializableStateRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<WorkerTaskSerializableStateRule>
 */
final class WorkerTaskSerializableStateRuleTest extends RuleTestCase
{
    public function testReportsUnsafeWorkerTaskState(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/worker-task-serializable-state.php'],
            [
                ['WorkerTask state cannot store Closure across a process boundary.', 24],
                ['WorkerTask state cannot store Phalanx\\Runtime\\RuntimeContext across a process boundary.', 41],
                ['WorkerTask state type Phalanx\\PHPStan\\Tests\\Rules\\Fixtures\\NonWorkerPayload is not process-boundary serializable; pass scalar/array/enum DTO data.', 58],
                ['WorkerTask array state cannot contain Closure across a process boundary.', 75],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new WorkerTaskSerializableStateRule(
            new PathPolicy(),
            self::getContainer()->getByType(ReflectionProvider::class),
        );
    }
}
