<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Testing;

use Phalanx\PHPStan\Rules\Testing\UseTestAppRule;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<UseTestAppRule>
 */
final class UseTestAppRuleTest extends RuleTestCase
{
    private ?TestingPathPolicy $pathPolicy = null;

    public function testFlagsBootCallsInsideIntegrationDirectory(): void
    {
        $this->analyse(
            [__DIR__ . '/../../Integration/Fixtures/UseTestAppViolation.php'],
            [
                [
                    'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of '
                    . 'Phalanx\\Application::starting(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    19,
                ],
                [
                    'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of '
                    . 'Phalanx\\Http\\Http::starting(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    24,
                ],
                [
                    'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of '
                    . 'Phalanx\\Console\\Console::command(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    29,
                ],
            ],
        );
    }

    public function testFlagsBootCallsInsideAcceptanceDirectory(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/UseTestAppViolation.php'],
            [
                [
                    'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of '
                    . 'Phalanx\\Application::starting(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    15,
                ],
                [
                    'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of '
                    . 'Phalanx\\Tui\\Tui::starting(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    20,
                ],
                [
                    'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of '
                    . 'Phalanx\\Tui\\Tui::app(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    25,
                ],
                [
                    'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of '
                    . 'Phalanx\\DevServer\\DevServer::starting(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    30,
                ],
            ],
        );
    }

    public function testRuleSpecificExemptionDoesNotHideOtherTestingRules(): void
    {
        $fixture = __DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/RuleSpecificExemptionStillReports.php';
        $this->pathPolicy = new TestingPathPolicy(noRawSleepExemptPaths: [$fixture]);

        $this->analyse(
            [$fixture],
            [
                [
                    'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of '
                    . 'Phalanx\\Application::starting(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    15,
                ],
            ],
        );
    }

    public function testFlagsBootCallsInsideSmokeDirectory(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Smoke/UseTestAppViolation.php'],
            [
                [
                    'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of '
                    . 'Phalanx\\Application::starting(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    13,
                ],
            ],
        );
    }

    public function testFlagsBootCallsInsideResilienceDirectory(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Resilience/UseTestAppViolation.php'],
            [
                [
                    'High-level Phalanx tests should boot through PhalanxTestCase::testApp() instead of '
                    . 'Phalanx\\Application::starting(). '
                    . 'Bypassing TestApp skips lens activation, fake registry resets, and ledger teardown assertions.',
                    13,
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
        return new UseTestAppRule($this->pathPolicy ?? new TestingPathPolicy());
    }
}
