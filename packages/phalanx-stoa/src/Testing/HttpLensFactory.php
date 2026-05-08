<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Testing;

use Phalanx\Stoa\StoaApplication;
use Phalanx\Testing\TestApp;
use Phalanx\Testing\TestLens;
use Phalanx\Testing\TestLensFactory;

final class HttpLensFactory implements TestLensFactory
{
    public function create(TestApp $app): TestLens
    {
        return new HttpLens($app, $app->primaryApp(StoaApplication::class));
    }
}
