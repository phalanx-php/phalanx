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
            internalPaths: ['src/Runtime/src/Worker'],
            enforcedPaths: ['src/Runtime/src'],
        ));

        self::assertTrue($policy->shouldReport('src/Runtime/src/Worker/Runtime.php'));
    }

    public function testRuleSpecificInternalPathsRemainSanctioned(): void
    {
        $policy = new ScopedRulePolicy(
            new PathPolicy(enforcedPaths: ['src/Runtime/src']),
            ['src/Runtime/src/System/Internal/SymfonyProcessAdapter.php'],
        );

        self::assertFalse($policy->shouldReport('src/Runtime/src/System/Internal/SymfonyProcessAdapter.php'));
    }
}
