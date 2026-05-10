<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Scope\StaticClosureOnlyRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<StaticClosureOnlyRule>
 */
final class StaticClosureOnlyRuleTest extends RuleTestCase
{
    public function testReportsNonStaticClosuresPassedToTaskPrimitives(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/static-closure-only.php'],
            [
                ['Arrow function passed to concurrent() must be declared static (static fn() =>) so it cannot capture $this in a long-running coroutine.', 16],
                ['Arrow function passed to race() must be declared static (static fn() =>) so it cannot capture $this in a long-running coroutine.', 21],
                ['Arrow function passed to map() must be declared static (static fn() =>) so it cannot capture $this in a long-running coroutine.', 26],
                ['Arrow function passed to go() must be declared static (static fn() =>) so it cannot capture $this in a long-running coroutine.', 30],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new StaticClosureOnlyRule(new PathPolicy());
    }
}
