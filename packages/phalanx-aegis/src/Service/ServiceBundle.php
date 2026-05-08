<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Testing\TestLens;

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

    abstract public function services(Services $services, AppContext $context): void;
}
