<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Stoa\Stoa;

$listen = $argv[1] ?? '127.0.0.1:8188';
$exampleHost = str_starts_with($listen, '0.0.0.0:')
    ? '127.0.0.1:' . substr($listen, strlen('0.0.0.0:'))
    : $listen;
$baseUrl = "http://{$exampleHost}";

echo <<<BOOT
Phalanx Server: Stoa contracts and validation demo
Listening on http://{$listen}

Try these examples:
curl -i '{$baseUrl}/tasks?owner=ops&limit=2'
curl -i -X POST {$baseUrl}/tasks -H 'Content-Type: application/json' -H 'Idempotency-Key: task-001' -d '{"title":"Review Stoa route contracts","priority":2}'
curl -i -X POST {$baseUrl}/tasks -H 'Content-Type: application/json' -H 'Idempotency-Key: task-002' -d '{"title":"no","priority":8}'
curl -i {$baseUrl}/tasks/1000

BOOT;

Stoa::starting()
    ->routes(__DIR__ . '/routes.php')
    ->listen($listen)
    ->quiet()
    ->run();
