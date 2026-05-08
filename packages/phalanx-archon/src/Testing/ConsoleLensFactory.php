<?php

declare(strict_types=1);

namespace Phalanx\Archon\Testing;

use Phalanx\Testing\TestApp;
use Phalanx\Testing\Lens;
use Phalanx\Testing\LensFactory;

final class ConsoleLensFactory implements LensFactory
{
    public function create(TestApp $app): Lens
    {
        return new ConsoleLens();
    }
}
