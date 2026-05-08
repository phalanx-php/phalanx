<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\Attribute\TestLens;
use Phalanx\Testing\TestLens as TestLensContract;
use RuntimeException;

#[TestLens(
    accessor: 'throwingReset',
    returns: self::class,
    factory: ThrowingResetLensFactory::class,
    requires: [],
)]
final class ThrowingResetLens implements TestLensContract
{
    public function reset(): void
    {
        throw new RuntimeException('reset deliberately failed');
    }
}
