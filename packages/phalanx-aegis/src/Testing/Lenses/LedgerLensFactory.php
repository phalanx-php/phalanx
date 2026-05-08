<?php

declare(strict_types=1);

namespace Phalanx\Testing\Lenses;

use Phalanx\Testing\TestApp;
use Phalanx\Testing\TestLens;
use Phalanx\Testing\TestLensFactory;

final class LedgerLensFactory implements TestLensFactory
{
    public function create(TestApp $app): TestLens
    {
        return new LedgerLens($app);
    }
}
