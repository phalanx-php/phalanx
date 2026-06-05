<?php

declare(strict_types=1);

namespace Phalanx\Http\Testing;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestLens;

/**
 * Marker bundle that activates HTTP's Lens on a TestApp.
 *
 * Adoption pattern in tests:
 *
 *     $http = Http::starting($context)->routes($routes)->build();
 *
 *     $app = $this->testApp($context, new TestableBundle())
 *         ->withPrimary($http);
 *
 *     $app->http->getJson('/users/42')->assertOk();
 *
 * The bundle registers no services itself — its sole job is to declare
 * Lens to TestApp's lens registry. Tests that need additional HTTP-side
 * configuration register their own ServiceBundles alongside.
 */
class TestableBundle extends ServiceBundle
{
    #[\Override]
    public static function lens(): TestLens
    {
        return TestLens::of(\Phalanx\Http\Testing\Lens::class);
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}
