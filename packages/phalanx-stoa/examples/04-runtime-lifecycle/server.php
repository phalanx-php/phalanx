<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\StoaDemo\Runtime\RuntimeLifecycleBundle;
use Phalanx\Stoa\Stoa;

$listen = $argv[1] ?? '127.0.0.1:8083';
$eventLog = $argv[2] ?? RuntimeLifecycleBundle::defaultEventLog();
$exampleHost = str_starts_with($listen, '0.0.0.0:')
    ? '127.0.0.1:' . substr($listen, strlen('0.0.0.0:'))
    : $listen;
$baseUrl = "http://{$exampleHost}";

@unlink($eventLog);

echo <<<BOOT
Phalanx Server: Stoa runtime lifecycle demo
Listening on http://{$listen}
Event log: {$eventLog}

Try these examples:
curl -i {$baseUrl}/runtime/health
curl -i {$baseUrl}/runtime/slow
curl -i --max-time 0.2 {$baseUrl}/runtime/disconnect
curl -i {$baseUrl}/runtime/events

BOOT;

Stoa::starting(['runtime_event_log' => $eventLog])
    ->providers(new RuntimeLifecycleBundle())
    ->routes(__DIR__ . '/routes.php')
    ->listen($listen)
    ->quiet()
    ->run();
