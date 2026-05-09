<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\StoaDemo\Realtime\Bundle\RealtimeBundle;
use Phalanx\Stoa\Stoa;

$listen = $argv[1] ?? '127.0.0.1:8084';
$base = 'http://' . (str_starts_with($listen, '0.0.0.0:')
    ? '127.0.0.1:' . substr($listen, strlen('0.0.0.0:'))
    : $listen);

$port = (int) substr($listen, strrpos($listen, ':') + 1);

echo <<<BOOT
Phalanx Server: Stoa realtime demo
Listening on http://{$listen}

Try:
curl -i {$base}/realtime/health
curl -N {$base}/realtime/counter            # SSE stream of 5 ticks
curl -i {$base}/realtime/proxy?upstream_port={$port}
curl -i {$base}/realtime/somewhere -H 'Upgrade: websocket' -H 'Connection: Upgrade'  # 426

BOOT;

Stoa::starting()
    ->providers(new RealtimeBundle())
    ->routes(__DIR__ . '/routes.php')
    ->listen($listen)
    ->quiet()
    ->run();
