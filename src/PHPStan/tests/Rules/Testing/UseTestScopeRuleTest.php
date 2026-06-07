<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Testing;

use Phalanx\PHPStan\Rules\Testing\UseTestScopeRule;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<UseTestScopeRule>
 */
final class UseTestScopeRuleTest extends RuleTestCase
{
    public function testFlagsDirectAppScopeCreationInHighLevelTests(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/UseTestScopeViolation.php'],
            [
                [
                    'High-level Phalanx tests should use $this->scope->run(...), $this->testApp(...), '
                    . 'or a package lens instead of direct createScope(); direct scopes bypass managed cleanup expectations.',
                    16,
                ],
                [
                    'High-level Phalanx tests should use $this->scope->run(...), $this->testApp(...), '
                    . 'or a package lens instead of direct createScope(); direct scopes bypass managed cleanup expectations.',
                    23,
                ],
            ],
        );
    }

    public function testIgnoresHelperMethodsNamedCreateScope(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/UseTestScopeValid.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new UseTestScopeRule(new TestingPathPolicy());
    }
}
