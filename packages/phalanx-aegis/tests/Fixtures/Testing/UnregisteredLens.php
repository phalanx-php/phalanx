<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\Attribute\TestLens;
use Phalanx\Testing\TestLens as TestLensContract;

/**
 * A lens that no fixture bundle activates. Used to exercise the
 * LensNotAvailable hard-fail path.
 */
#[TestLens(
    accessor: 'unregistered',
    returns: self::class,
    factory: FixtureLensFactory::class,
    requires: [],
)]
final class UnregisteredLens implements TestLensContract
{
    public function reset(): void
    {
    }
}
