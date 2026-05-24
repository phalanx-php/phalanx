<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Worker\WorkerTaskScopeContractRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<WorkerTaskScopeContractRule>
 */
final class WorkerTaskScopeContractRuleTest extends RuleTestCase
{
    public function testReportsUnsafeWorkerTaskInvokeScope(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/worker-task-scope-contract.php'],
            [
                ['WorkerTask::__invoke() must accept WorkerScope or narrow Scope, not ExecutionScope/TaskScope/TaskExecutor.', 18],
                ['WorkerTask::__invoke() must accept Phalanx\\Worker\\WorkerScope or Phalanx\\Scope\\Scope.', 30],
                ['WorkerTask::__invoke() must accept WorkerScope or narrow Scope, not ExecutionScope/TaskScope/TaskExecutor.', 58],
                ['WorkerTask::__invoke() must accept Phalanx\\Worker\\WorkerScope or Phalanx\\Scope\\Scope.', 70],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new WorkerTaskScopeContractRule(
            new PathPolicy(),
            self::getContainer()->getByType(ReflectionProvider::class),
        );
    }
}
