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
                ['inWorker() cannot receive a closure or Task::of() closure adapter; pass a serializable Scopeable|Executable task object.', 20],
                ['inWorker() cannot receive a closure or Task::of() closure adapter; pass a serializable Scopeable|Executable task object.', 21],
                ['inWorker() cannot receive a closure or Task::of() closure adapter; pass a serializable Scopeable|Executable task object.', 23],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new NoClosureBoundaryRule(new PathPolicy());
    }
}
