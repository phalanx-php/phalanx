<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Runtime\LifecycleBoundaryRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<LifecycleBoundaryRule>
 */
final class LifecycleBoundaryRuleTest extends RuleTestCase
{
    public function testReportsDirectLifecycleBoundaryBypasses(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/lifecycle-boundary.php'],
            [
                [
                    'Package code must use a lifecycle owner/adapter instead of calling RuntimeMemory->resources mutators directly.',
                    13,
                ],
                [
                    'Runtime lifecycle table rows are managed-resource truth; use RuntimeMemory resources/events APIs instead of direct table mutation.',
                    14,
                ],
                [
                    'Runtime owns lifecycle tables; use RuntimeMemory managed-resource APIs instead of constructing Swoole\\Table in package code.',
                    15,
                ],
            ],
        );
    }

    public function testAllowsSanctionedLifecycleOwners(): void
    {
        $fixture = __DIR__ . '/Fixtures/lifecycle-boundary-internal.php';

        $this->analyse([$fixture], []);
    }

    protected function getRule(): Rule
    {
        return new LifecycleBoundaryRule(
            new PathPolicy(),
            [__DIR__ . '/Fixtures/lifecycle-boundary-internal.php'],
        );
    }
}
