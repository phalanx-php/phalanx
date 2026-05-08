<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\Attribute\Lens;
use Phalanx\Testing\Lens as LensContract;
use RuntimeException;

#[Lens(
    accessor: 'throwingReset',
    returns: self::class,
    factory: ThrowingResetLensFactory::class,
    requires: [],
)]
final class ThrowingResetLens implements LensContract
{
    public function reset(): void
    {
        throw new RuntimeException('reset deliberately failed');
    }
}
