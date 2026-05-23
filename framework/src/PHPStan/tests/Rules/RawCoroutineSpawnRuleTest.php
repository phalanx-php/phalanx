<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Concurrency\RawCoroutineSpawnRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<RawCoroutineSpawnRule>
 */
final class RawCoroutineSpawnRuleTest extends RuleTestCase
{
    public function testReportsRawCoroutineSpawn(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/raw-coroutine-spawn.php'],
            [
                ['Raw coroutine spawning bypasses Phalanx scope/runtime truth; use $scope->go(), $scope->concurrent(), $scope->defer(), or a framework-owned spawn helper.', 14],
                ['Raw coroutine spawning bypasses Phalanx scope/runtime truth; use $scope->go(), $scope->concurrent(), $scope->defer(), or a framework-owned spawn helper.', 17],
            ],
        );
    }

    public function testAllowsSanctionedSpawnInternals(): void
    {
        $fixture = __DIR__ . '/Fixtures/raw-coroutine-spawn-internal.php';

        $this->analyse([$fixture], []);
    }

    protected function getRule(): Rule
    {
        return new RawCoroutineSpawnRule(
            new PathPolicy(),
            [__DIR__ . '/Fixtures/raw-coroutine-spawn-internal.php'],
        );
    }
}
