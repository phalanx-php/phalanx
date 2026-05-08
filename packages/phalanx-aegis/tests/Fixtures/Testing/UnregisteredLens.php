<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\Attribute\Lens;
use Phalanx\Testing\Lens as LensContract;

/**
 * A lens that no fixture bundle activates. Used to exercise the
 * LensNotAvailable hard-fail path.
 */
#[Lens(
    accessor: 'unregistered',
    returns: self::class,
    factory: FixtureLensFactory::class,
    requires: [],
)]
final class UnregisteredLens implements LensContract
{
    public function reset(): void
    {
    }
}
