<?php

declare(strict_types=1);

namespace Phalanx\Http\Testing;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestLens;

/**
 * Marker bundle that activates Http's HttpLens on a TestApp.
 *
 * Adoption pattern in tests:
 *
 *     $http = Http::starting($context)->routes($routes)->build();
 *
 *     $app = $this->testApp($context, new HttpTestableBundle())
 *         ->withPrimary($http);
 *
 *     $app->http->getJson('/users/42')->assertOk();
 *
 * The bundle registers no services itself — its sole job is to declare
 * HttpLens to TestApp's lens registry. Tests that need additional Http-side
 * configuration register their own ServiceBundles alongside.
 */
class HttpTestableBundle extends ServiceBundle
{
    #[\Override]
    public static function lens(): TestLens
    {
        return TestLens::of(HttpLens::class);
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}
