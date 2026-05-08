<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\TestLens as TestLensContract;

/**
 * A lens deliberately missing its #[TestLens] attribute. Used to verify
 * TestApp surfaces a clear error when a bundle nominates a lens class
 * that wasn't authored with the attribute.
 */
final class UnattributedLens implements TestLensContract
{
    public function reset(): void
    {
    }
}
