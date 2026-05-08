<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\TestApp;
use Phalanx\Testing\TestLens;
use Phalanx\Testing\TestLensFactory;

final class RecordingLensFactory implements TestLensFactory
{
    public function create(TestApp $app): TestLens
    {
        return new RecordingLens($app->service(RecordingLensTarget::class));
    }
}
