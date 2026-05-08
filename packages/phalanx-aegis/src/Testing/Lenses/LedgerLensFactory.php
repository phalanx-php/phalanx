<?php

declare(strict_types=1);

namespace Phalanx\Testing\Lenses;

use Phalanx\Testing\Lens;
use Phalanx\Testing\LensFactory;
use Phalanx\Testing\TestApp;

final class LedgerLensFactory implements LensFactory
{
    public function create(TestApp $app): Lens
    {
        return new LedgerLens($app);
    }
}
