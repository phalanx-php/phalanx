<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Support;

use Phalanx\PHPStan\Support\PathPolicy;
use Phalanx\PHPStan\Support\ScopedRulePolicy;
use PHPUnit\Framework\TestCase;

final class ScopedRulePolicyTest extends TestCase
{
    public function testRuleSpecificPoliciesDoNotInheritBroadInternalPaths(): void
    {
        $policy = new ScopedRulePolicy(new PathPolicy(
            internalPaths: ['framework/src/Aegis/src/Worker'],
            enforcedPaths: ['framework/src/Aegis/src'],
        ));

        self::assertTrue($policy->shouldReport('framework/src/Aegis/src/Worker/Runtime.php'));
    }

    public function testRuleSpecificInternalPathsRemainSanctioned(): void
    {
        $policy = new ScopedRulePolicy(
            new PathPolicy(enforcedPaths: ['framework/src/Aegis/src']),
            ['framework/src/Aegis/src/System/Internal/SymfonyProcessAdapter.php'],
        );

        self::assertFalse($policy->shouldReport('framework/src/Aegis/src/System/Internal/SymfonyProcessAdapter.php'));
    }
}
