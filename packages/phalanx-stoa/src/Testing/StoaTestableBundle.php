<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Testing;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestableBundle;
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
final class StoaTestableBundle implements ServiceBundle, TestableBundle
{
    public function services(Services $services, array $context): void
    {
    }

    /** @return list<class-string<TestLens>> */
    public static function testLenses(): array
    {
        return [HttpLens::class];
    }
}
