<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Runtime;

use Phalanx\Runtime\RuntimeCapability;
use Phalanx\Runtime\RuntimePolicy;
use PHPUnit\Framework\TestCase;

/**
 * use_fiber_context defaults on. The default factory `phalanxManaged()`
 * carries it. Operators can opt out (rare; only useful when a third-party
 * extension conflicts with native Fiber context switching).
 */
final class RuntimePolicyFiberContextTest extends TestCase
{
    public function testPhalanxManagedEnablesFiberContext(): void
    {
        $policy = RuntimePolicy::phalanxManaged();

        self::assertTrue($policy->useFiberContext);
    }

    public function testForCapabilitiesEnablesFiberContext(): void
    {
        $policy = RuntimePolicy::forCapabilities(RuntimeCapability::Network);

        self::assertTrue($policy->useFiberContext);
    }

    public function testCoroutineOptionsRendersFlag(): void
    {
        $policy = RuntimePolicy::phalanxManaged();

        self::assertSame(['use_fiber_context' => true], $policy->coroutineOptions());
    }

    public function testExplicitOptOutHonored(): void
    {
        $policy = new RuntimePolicy(
            name: 'opt-out',
            requiredFlags: 0,
            sensitiveFlags: 0,
            useFiberContext: false,
        );

        self::assertFalse($policy->useFiberContext);
        self::assertSame(['use_fiber_context' => false], $policy->coroutineOptions());
    }
}
