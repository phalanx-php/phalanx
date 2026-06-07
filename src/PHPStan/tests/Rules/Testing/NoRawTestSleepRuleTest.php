<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Testing;

use Phalanx\PHPStan\Rules\Testing\NoRawTestSleepRule;
use Phalanx\PHPStan\Support\TestingPathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<NoRawTestSleepRule>
 */
final class NoRawTestSleepRuleTest extends RuleTestCase
{
    public function testFlagsRawSleepsInHighLevelTests(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/NoRawTestSleepViolation.php'],
            [
                [
                    'High-level Phalanx tests should not use Swoole\\Coroutine::sleep(); '
                    . 'use deterministic TestApp/lens probes, $scope->delay(...), or an await helper instead.',
                    14,
                ],
                [
                    'High-level Phalanx tests should not use Swoole\\Coroutine::usleep(); '
                    . 'use deterministic TestApp/lens probes, $scope->delay(...), or an await helper instead.',
                    15,
                ],
                [
                    'High-level Phalanx tests should not use Phalanx\\Concurrency\\Co::sleep(); '
                    . 'use deterministic TestApp/lens probes, $scope->delay(...), or an await helper instead.',
                    16,
                ],
                [
                    'High-level Phalanx tests should not use sleep(); '
                    . 'use deterministic TestApp/lens probes, $scope->delay(...), or an await helper instead.',
                    17,
                ],
                [
                    'High-level Phalanx tests should not use usleep(); '
                    . 'use deterministic TestApp/lens probes, $scope->delay(...), or an await helper instead.',
                    18,
                ],
            ],
        );
    }

    public function testIgnoresSafeDelayAndStringFixtureCode(): void
    {
        $this->analyse(
            [__DIR__ . '/../Fixtures/TestingPaths/tests/Acceptance/NoRawTestSleepValid.php'],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new NoRawTestSleepRule(new TestingPathPolicy());
    }
}
