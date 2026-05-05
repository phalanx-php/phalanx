<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\HandleAgentTask;
use Phalanx\Athena\Athena;
use Phalanx\Postgres\PgServiceBundle;
use Phalanx\Redis\RedisPubSub;
use Phalanx\Redis\RedisServiceBundle;

$app = Athena::starting()
    ->providers(
        new RedisServiceBundle(),
        new PgServiceBundle(),
    )
    ->build();

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
