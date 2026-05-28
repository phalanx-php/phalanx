#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

use Phalanx\Application;
use Phalanx\Archon\ConsoleRunner;
use Phalanx\Athena\AiServiceBundle;
use Phalanx\Grammata\FilesystemServiceBundle;
use Sentinel\SentinelServiceBundle;

return static function (array $context): ConsoleRunner {
    $context['SWARM_SESSION']   ??= 'sentinel-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $context['SWARM_WORKSPACE'] ??= 'sentinel';
    $context['DAEMON8_APP']     ??= 'sentinel';
    $context['DAEMON8_URL']     ??= 'http://localhost:8888';

    $app = Application::starting($context)
        ->providers(
            new AiServiceBundle(),
            new FilesystemServiceBundle(),
            new SentinelServiceBundle(),
        )
        ->compile();

    return ConsoleRunner::withCommands($app, __DIR__ . '/commands');
};
