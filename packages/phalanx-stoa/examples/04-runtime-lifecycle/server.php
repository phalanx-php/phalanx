<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\StoaDemo\Runtime\RuntimeLifecycleBundle;
use Phalanx\Stoa\Stoa;

$listen = $argv[1] ?? '127.0.0.1:8083';
$exampleHost = str_starts_with($listen, '0.0.0.0:')
    ? '127.0.0.1:' . substr($listen, strlen('0.0.0.0:'))
    : $listen;
$baseUrl = "http://{$exampleHost}";

echo <<<BOOT
Phalanx Server: Stoa runtime lifecycle demo
Listening on http://{$listen}

Try these examples:
curl -i {$baseUrl}/runtime/health
curl -i {$baseUrl}/runtime/slow
curl -i --max-time 0.2 {$baseUrl}/runtime/disconnect
curl -i {$baseUrl}/runtime/events
curl -s {$baseUrl}/runtime/events | php -r 'echo json_encode(json_decode(stream_get_contents(STDIN), true), JSON_PRETTY_PRINT) . PHP_EOL;'

After the disconnect example, inspect /runtime/events to see the cancellation and cleanup events.

BOOT;

Stoa::starting()
    ->providers(new RuntimeLifecycleBundle())
    ->routes(__DIR__ . '/routes.php')
    ->listen($listen)
    ->quiet()
    ->run();
