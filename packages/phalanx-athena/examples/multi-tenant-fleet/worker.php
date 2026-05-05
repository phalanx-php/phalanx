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
Status: ready

Nothing is wrong with the worker wiring. Redis and Postgres connections are
skipped by default so the demo does not touch local services unless requested.

Current configuration:

BOOT;

    printf("  %-18s %s\n", 'ATHENA_DEMO_LIVE', 'disabled');
    printf("  %-18s %s\n", 'REDIS_URL', phalanxAthenaExampleEnvStatus('REDIS_URL'));
    printf("  %-18s %s\n", 'REDIS_HOST', phalanxAthenaExampleEnvStatus('REDIS_HOST'));
    printf("  %-18s %s\n", 'PG_HOST', phalanxAthenaExampleEnvStatus('PG_HOST'));
    printf("  %-18s %s\n", 'PG_DATABASE', phalanxAthenaExampleEnvStatus('PG_DATABASE'));

    $instructions = <<<'BOOT'

Run the live worker:
  ATHENA_DEMO_LIVE=1 %s

Defaults if unset:
  Redis 127.0.0.1:6379
  Postgres localhost:5432

BOOT;

    printf(
        $instructions,
        phalanxAthenaExampleComposerCommand('demo:athena:worker:fleet', 'demo:worker:fleet'),
    );
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
Status: running

Subscribed endpoint:
  Redis channel agent:tasks

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
