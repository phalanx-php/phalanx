<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Testing\TestLens;

/**
 * Member ordering note: phpcs ClassStructure (Slevomat) places public
 * static methods before the abstract instance method on this base. The
 * project's documented 12-step convention puts abstract methods at #3
 * (before public statics at #10), but Slevomat's enforced ordering for
 * concrete-with-abstract shapes wins here. The static hooks (lens(),
 * harness()) are virtual extension points; the abstract services() is
 * the contract every subclass implements.
 */
abstract class ServiceBundle
{
    /**
     * Test lenses this bundle exposes via TestApp accessors.
     * Override and return TestLens::of(MyLens::class, ...) when the
     * bundle has captured/observable test surfaces.
     */
    public static function lens(): TestLens
    {
        return TestLens::none();
    }

    /**
     * Boot requirements this bundle needs before AppHost is built.
     * Override and return BootHarness::of(Required::env('...'), Probe::tcp(...))
     * to surface friendly cannot-run errors with remediation.
     */
    public static function harness(): BootHarness
    {
        return BootHarness::none();
    }

    /**
     * Wire the bundle's services into the supplied container using values
     * from $context. Implementations must be idempotent for a single
     * Services instance (each container builds bundles once at compile
     * time) and must read every config value through AppContext typed
     * accessors -- never via process-environment globals or the
     * superglobal env arrays. Stateful clients, pools, and connections
     * registered here own their lifecycle via Services::onShutdown().
     */
    abstract public function services(Services $services, AppContext $context): void;
}
