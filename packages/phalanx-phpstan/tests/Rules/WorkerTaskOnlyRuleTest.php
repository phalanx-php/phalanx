<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Worker\WorkerTaskOnlyRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<WorkerTaskOnlyRule>
 */
final class WorkerTaskOnlyRuleTest extends RuleTestCase
{
    public function testReportsNonWorkerTaskDispatches(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/worker-task-only.php'],
            [
                ['inWorker() can only dispatch named objects implementing Phalanx\\Worker\\WorkerTask.', 15],
                ['parallel() can only dispatch named objects implementing Phalanx\\Worker\\WorkerTask.', 16],
                ['settleParallel() can only dispatch named objects implementing Phalanx\\Worker\\WorkerTask.', 17],
                ['mapParallel() can only dispatch named objects implementing Phalanx\\Worker\\WorkerTask.', 18],
                ['parallel() can only dispatch named objects implementing Phalanx\\Worker\\WorkerTask.', 19],
                ['mapParallel() can only dispatch named objects implementing Phalanx\\Worker\\WorkerTask.', 20],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new WorkerTaskOnlyRule(new PathPolicy());
    }
}
