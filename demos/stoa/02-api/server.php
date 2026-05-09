<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\StoaDemo\Api\Bundle\ApiServiceBundle;
use Phalanx\Stoa\Stoa;

$listen = $argv[1] ?? '127.0.0.1:8082';
$base = 'http://' . (str_starts_with($listen, '0.0.0.0:')
    ? '127.0.0.1:' . substr($listen, strlen('0.0.0.0:'))
    : $listen);

echo <<<BOOT
Phalanx Server: Stoa API demo
Listening on http://{$listen}

Try:
curl -i {$base}/api/v1/tasks/42
curl -i {$base}/api/v1/me -H 'Authorization: Bearer demo-token'
curl -i -X POST {$base}/api/v1/tasks \\
  -H 'Authorization: Bearer demo-token' \\
  -H 'Idempotency-Key: task-001' \\
  -H 'Content-Type: application/json' \\
  -d '{"title":"Review managed runtime claims","priority":2}'

BOOT;

Stoa::starting()
    ->providers(new ApiServiceBundle())
    ->routes(__DIR__ . '/routes.php')
    ->listen($listen)
    ->quiet()
    ->run();
