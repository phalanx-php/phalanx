<?php

declare(strict_types=1);

namespace Phalanx\Harness;

use Phalanx\Harness\Agent\AthenaServiceBundle;
use Phalanx\Harness\Ui\UiApp;
use Phalanx\Iris\HttpServiceBundle;
use Phalanx\Theatron\Theatron;
use Phalanx\Theatron\TheatronApp;
use Phalanx\Theatron\TheatronBuilder;
use Phalanx\Theatron\TheatronServiceBundle;

final class Harness
{
    /** @param array<string,mixed> $context */
    public static function app(array $context = []): TheatronBuilder
    {
        return Theatron::app($context)
            ->store(UiApp::store())
            ->screens(UiApp::screens())
            ->globalBindings(UiApp::bindings())
            ->providers(
                static fn(TheatronApp $app): TheatronServiceBundle => new TheatronServiceBundle($app),
                new HttpServiceBundle(),
                AthenaServiceBundle::ollama(),
            )
            ->devtools();
    }
}
