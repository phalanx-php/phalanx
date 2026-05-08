<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestableBundle;

/**
 * Bundle that nominates the same lens class as FixtureBundle but is a
 * distinct provider. Used to prove deduplication of identical-factory
 * registrations.
 */
final class ConflictingFixtureBundle implements ServiceBundle, TestableBundle
{
    public function services(Services $services, array $context): void
    {
    }

    /** @return list<class-string<\Phalanx\Testing\TestLens>> */
    public static function testLenses(): array
    {
        return [FixtureLens::class];
    }
}
