<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\HandleAgentTask;
use Phalanx\Athena\Athena;
use Phalanx\Postgres\PgServiceBundle;
use Phalanx\Redis\RedisPubSub;
use Phalanx\Redis\RedisServiceBundle;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

/** @var array<string, mixed> $context */
$context = phalanxAthenaExampleContext($argv ?? []);

if (($context['ATHENA_DEMO_LIVE'] ?? false) !== true) {
    echo <<<'BOOT'
Multi-Tenant Fleet - Worker
============================
Worker wiring is ready.
Redis subscription is skipped by default; set ATHENA_DEMO_LIVE=1 to connect.

BOOT;
    exit(0);
}

$exitCode = Athena::starting($context)
    ->providers(
        new RedisServiceBundle(),
        new PgServiceBundle(),
    )
    ->run(Task::named(
        'demo.athena.multi-tenant-fleet.worker',
        static function (ExecutionScope $scope): int {
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

            return 0;
        },
    ));

exit((int) $exitCode);
