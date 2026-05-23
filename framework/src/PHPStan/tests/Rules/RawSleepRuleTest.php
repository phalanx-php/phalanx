<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Cancellation\RawSleepRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<RawSleepRule>
 */
final class RawSleepRuleTest extends RuleTestCase
{
    public function testReportsRawCoroutineSleep(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/raw-sleep.php'],
            [
                ['Use $scope->delay() instead of OpenSwoole\Coroutine::usleep() inside scoped task code; raw sleep does not observe the Phalanx cancellation token.', 13],
                ['Use $scope->delay() instead of Swoole\Coroutine::sleep() inside scoped task code; raw sleep does not observe the Phalanx cancellation token.', 14],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new RawSleepRule(new PathPolicy());
    }
}
