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
            internalPaths: ['packages/phalanx-aegis/src/Worker'],
            enforcedPaths: ['packages/phalanx-aegis/src'],
        ));

        self::assertTrue($policy->shouldReport('packages/phalanx-aegis/src/Worker/Runtime.php'));
    }

    public function testRuleSpecificInternalPathsRemainSanctioned(): void
    {
        $policy = new ScopedRulePolicy(
            new PathPolicy(enforcedPaths: ['packages/phalanx-aegis/src']),
            ['packages/phalanx-aegis/src/System/Internal/SymfonyProcessAdapter.php'],
        );

        self::assertFalse($policy->shouldReport('packages/phalanx-aegis/src/System/Internal/SymfonyProcessAdapter.php'));
    }
}
