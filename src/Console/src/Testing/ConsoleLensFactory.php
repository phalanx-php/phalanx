<?php

declare(strict_types=1);

namespace Phalanx\Console\Testing;

use Phalanx\Testing\Lens;
use Phalanx\Testing\LensFactory;
use Phalanx\Testing\TestApp;

final class ConsoleLensFactory implements LensFactory
{
    public function create(TestApp $app): Lens
    {
        return new ConsoleLens();
    }
}
