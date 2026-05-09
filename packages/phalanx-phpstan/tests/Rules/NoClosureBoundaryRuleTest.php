<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Worker\NoClosureBoundaryRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoClosureBoundaryRule>
 */
final class NoClosureBoundaryRuleTest extends RuleTestCase
{
    public function testReportsClosuresCrossingWorkerBoundary(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/no-closure-boundary.php'],
            [
                ['inWorker() cannot receive a closure or Task::of() closure adapter; pass serializable WorkerTask objects.', 20],
                ['inWorker() cannot receive a closure or Task::of() closure adapter; pass serializable WorkerTask objects.', 21],
                ['inWorker() cannot receive a closure or Task::of() closure adapter; pass serializable WorkerTask objects.', 23],
                ['parallel() cannot receive a closure or Task::of() closure adapter; pass serializable WorkerTask objects.', 24],
                ['settleParallel() cannot receive a closure or Task::of() closure adapter; pass serializable WorkerTask objects.', 25],
                ['parallel() cannot receive a closure or Task::of() closure adapter; pass serializable WorkerTask objects.', 26],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new NoClosureBoundaryRule(new PathPolicy());
    }
}
