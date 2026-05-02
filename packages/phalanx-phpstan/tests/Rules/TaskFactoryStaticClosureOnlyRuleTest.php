<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Scope\TaskFactoryStaticClosureOnlyRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<TaskFactoryStaticClosureOnlyRule>
 */
final class TaskFactoryStaticClosureOnlyRuleTest extends RuleTestCase
{
    public function testReportsNonStaticClosuresPassedToTaskFactories(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/static-closure-only.php'],
            [
                ['Closure passed to Task::of() must be static so it cannot capture $this in a long-running coroutine.', 27],
                ['Closure passed to Task::named() must be static so it cannot capture $this in a long-running coroutine.', 28],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new TaskFactoryStaticClosureOnlyRule(new PathPolicy());
    }
}
