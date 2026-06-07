<?php

declare(strict_types=1);

namespace Phalanx\Worker\Testing;

use Phalanx\Testing\Lens as LensContract;
use Phalanx\Testing\LensFactory as LensFactoryContract;
use Phalanx\Testing\TestApp;

final class LensFactory implements LensFactoryContract
{
    public function create(TestApp $app): LensContract
    {
        return new Lens($app);
    }
}
