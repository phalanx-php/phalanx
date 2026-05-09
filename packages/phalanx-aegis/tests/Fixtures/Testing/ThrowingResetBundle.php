<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestLens;

final class ThrowingResetBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
    }

    #[\Override]
    public static function lens(): TestLens
    {
        return TestLens::of(ThrowingResetLens::class);
    }
}
