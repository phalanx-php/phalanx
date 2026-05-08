<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\Lens as LensContract;

/**
 * A lens deliberately missing its #[Lens] attribute. Used to verify
 * TestApp surfaces a clear error when a bundle nominates a lens class
 * that wasn't authored with the attribute.
 */
final class UnattributedLens implements LensContract
{
    public function reset(): void
    {
    }
}
