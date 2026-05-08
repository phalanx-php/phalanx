<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Testing;

use Phalanx\Stoa\StoaApplication;
use Phalanx\Testing\TestApp;
use Phalanx\Testing\Lens;
use Phalanx\Testing\LensFactory;

final class HttpLensFactory implements LensFactory
{
    public function create(TestApp $app): Lens
    {
        return new HttpLens($app, $app->primaryApp(StoaApplication::class));
    }
}
