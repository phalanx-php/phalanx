<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\TestApp;
use Phalanx\Testing\Lens;
use Phalanx\Testing\LensFactory;

final class ThrowingResetLensFactory implements LensFactory
{
    public function create(TestApp $app): Lens
    {
        return new ThrowingResetLens();
    }
}
