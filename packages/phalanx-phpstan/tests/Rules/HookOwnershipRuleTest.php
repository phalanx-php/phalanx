<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules;

use Phalanx\PHPStan\Rules\Runtime\HookOwnershipRule;
use Phalanx\PHPStan\Support\PathPolicy;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<HookOwnershipRule>
 */
final class HookOwnershipRuleTest extends RuleTestCase
{
    public function testReportsPackageLocalRuntimeHookConfiguration(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/runtime-hook-ownership.php'],
            [
                ['Aegis owns OpenSwoole runtime hook activation; use RuntimePolicy, RuntimeHooks, or RuntimeCapability instead of configuring hooks in package code.', 14],
                ['Aegis owns OpenSwoole runtime hook flag; use RuntimePolicy, RuntimeHooks, or RuntimeCapability instead of configuring hooks in package code.', 14],
                ['Aegis owns OpenSwoole coroutine hook options; use RuntimePolicy, RuntimeHooks, or RuntimeCapability instead of configuring hooks in package code.', 15],
                ['Aegis owns OpenSwoole runtime hook flag; use RuntimePolicy, RuntimeHooks, or RuntimeCapability instead of configuring hooks in package code.', 15],
                ['Aegis owns OpenSwoole global runtime hook flag; use RuntimePolicy, RuntimeHooks, or RuntimeCapability instead of configuring hooks in package code.', 17],
            ],
        );
    }

    public function testAllowsSanctionedRuntimeInternals(): void
    {
        $fixture = __DIR__ . '/Fixtures/runtime-hook-ownership-internal.php';

        $this->analyse([$fixture], []);
    }

    protected function getRule(): Rule
    {
        return new HookOwnershipRule(
            new PathPolicy(),
            [__DIR__ . '/Fixtures/runtime-hook-ownership-internal.php'],
        );
    }
}
