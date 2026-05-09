<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Testing;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestLens;

/**
 * Marker bundle that activates Stoa's HttpLens on a TestApp.
 *
 * Adoption pattern in tests:
 *
 *     $stoa = Stoa::starting($context)->routes($routes)->build();
 *
 *     $app = $this->testApp($context, new StoaTestableBundle())
 *         ->withPrimary($stoa);
 *
 *     $app->http->getJson('/users/42')->assertOk();
 *
 * The bundle registers no services itself — its sole job is to declare
 * HttpLens to TestApp's lens registry. Tests that need additional Stoa-side
 * configuration register their own ServiceBundles alongside.
 */
class StoaTestableBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
    }

    #[\Override]
    public static function lens(): TestLens
    {
        return TestLens::of(HttpLens::class);
    }
}
