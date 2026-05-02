<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Acme\StoaDemo\Advanced\DemoServiceBundle;
use Phalanx\Application;
use Phalanx\Stoa\StoaRunner;

$listen = $argv[1] ?? '127.0.0.1:8082';
$app = Application::starting()
    ->providers(new DemoServiceBundle())
    ->compile();
$exampleHost = str_starts_with($listen, '0.0.0.0:')
    ? '127.0.0.1:' . substr($listen, strlen('0.0.0.0:'))
    : $listen;
$baseUrl = "http://{$exampleHost}";

echo <<<BOOT
Phalanx Server: Stoa advanced API demo
Listening on http://{$listen}

Try these one-line requests:
curl -i {$baseUrl}/api/v1/health
curl -i {$baseUrl}/api/v1/reports/2026/05
curl -i {$baseUrl}/api/v1/admin/me -H 'Authorization: Bearer demo-token'
curl -i -X POST {$baseUrl}/api/v1/admin/jobs -H 'Authorization: Bearer demo-token' -H 'Content-Type: application/json' -d '{"name":"nightly-ledger-rollup","priority":4}'

BOOT;

StoaRunner::from($app)
    ->withRoutes(require __DIR__ . '/routes.php')
    ->run($listen);
