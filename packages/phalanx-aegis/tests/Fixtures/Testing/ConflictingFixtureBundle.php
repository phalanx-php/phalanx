<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestLens;

/**
 * Bundle that nominates the same lens class as FixtureBundle but is a
 * distinct provider. Used to prove deduplication of identical-factory
 * registrations.
 */
final class ConflictingFixtureBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
    }

    public static function lens(): TestLens
    {
        return TestLens::of(FixtureLens::class);
    }
}
