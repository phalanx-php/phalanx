<?php

declare(strict_types=1);

namespace Phalanx\Http\Testing;

use Phalanx\Http\HttpApplication;
use Phalanx\Testing\Lens;
use Phalanx\Testing\LensFactory;
use Phalanx\Testing\TestApp;

final class HttpLensFactory implements LensFactory
{
    public function create(TestApp $app): Lens
    {
        return new HttpLens($app, $app->primaryApp(HttpApplication::class));
    }
}
