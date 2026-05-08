<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestLens;

final class RecordingBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
    }

    public static function lens(): TestLens
    {
        return TestLens::of(RecordingLens::class);
    }
}
