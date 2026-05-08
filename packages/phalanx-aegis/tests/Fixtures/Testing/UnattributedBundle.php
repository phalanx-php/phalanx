<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestableBundle;

final class UnattributedBundle implements ServiceBundle, TestableBundle
{
    public function services(Services $services, array $context): void
    {
    }

    /** @return list<class-string<\Phalanx\Testing\Lens>> */
    public static function testLenses(): array
    {
        return [UnattributedLens::class];
    }
}
