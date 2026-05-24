<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Scope\UnusedClosureParameterRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<UnusedClosureParameterRule>
 */
final class UnusedClosureParameterRuleTest extends RuleTestCase
{
    public function testReportsUnusedClosureParameters(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/unused-closure-parameter.php'],
            [
                ['Closure parameter $scope is never used. Prefix with underscore ($_scope) if intentionally unused.', 12],
                ['Closure parameter $x is never used. Prefix with underscore ($_x) if intentionally unused.', 27],
                ['Closure parameter $a is never used. Prefix with underscore ($_a) if intentionally unused.', 33],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new UnusedClosureParameterRule();
    }
}
