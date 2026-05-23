<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Testing;

use Phalanx\PHPStan\Rules\Testing\UseTestAppRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<UseTestAppRule>
 */
final class UseTestAppRuleTest extends RuleTestCase
{
    public function testFlagsBootCallsInsideIntegrationDirectory(): void
    {
        $this->analyse(
            [__DIR__ . '/../../Integration/Fixtures/UseTestAppViolation.php'],
            [
                [
                    'Integration tests should boot through PhalanxTestCase::testApp() instead of Phalanx\\Application::starting(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    18,
                ],
                [
                    'Integration tests should boot through PhalanxTestCase::testApp() instead of Phalanx\\Stoa\\Stoa::starting(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    23,
                ],
            ],
        );
    }

    public function testIgnoresBootCallsOutsideTestDirectories(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/UseTestAppOutsideIntegrationDir.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new UseTestAppRule();
    }
}
