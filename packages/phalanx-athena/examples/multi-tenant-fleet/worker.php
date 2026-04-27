<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Acme\HandleAgentTask;
use Phalanx\Athena\AiServiceBundle;
use Phalanx\Application;
use Phalanx\Postgres\PgServiceBundle;
use Phalanx\Redis\RedisPubSub;
use Phalanx\Redis\RedisServiceBundle;

$app = Application::starting()
    ->providers(
        new AiServiceBundle(),
        new RedisServiceBundle(),
        new PgServiceBundle(),
    )
    ->compile();

$scope = $app->createScope();

echo <<<'BOOT'
Multi-Tenant Fleet - Worker
============================
Subscribed to agent:tasks Redis channel
Waiting for tasks...

BOOT;

$scope->service(RedisPubSub::class)->subscribeEach(
    'agent:tasks',
    new HandleAgentTask(),
    $scope,
);
