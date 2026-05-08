<?php

declare(strict_types=1);

namespace Phalanx\Archon\Testing;

use Phalanx\Testing\TestApp;
use Phalanx\Testing\TestLens;
use Phalanx\Testing\TestLensFactory;

final class ConsoleLensFactory implements TestLensFactory
{
    public function create(TestApp $app): TestLens
    {
        return new ConsoleLens();
    }
}
