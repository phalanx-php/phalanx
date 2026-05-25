<?php

declare(strict_types=1);

namespace Phalanx\Harness;

use Phalanx\Harness\Agent\AthenaServiceBundle;
use Phalanx\Harness\Template\TemplateApp;
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
            ->store(TemplateApp::store())
            ->screens(TemplateApp::screens())
            ->globalBindings(TemplateApp::bindings())
            ->providers(
                static fn(TheatronApp $app): TheatronServiceBundle => new TheatronServiceBundle($app),
                new HttpServiceBundle(),
                AthenaServiceBundle::ollama(),
            )
            ->devtools();
    }
}
