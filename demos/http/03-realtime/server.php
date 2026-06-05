<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Acme\HttpDemo\Realtime\Bundle\RealtimeBundle;
use Phalanx\Http\Http;

return static fn(array $context): \Closure => static function () use ($context): int {
    $listen = $context['argv'][1] ?? '127.0.0.1:8084';

    return \Phalanx\Http\Server::starting($context)
        ->providers(new RealtimeBundle())
        ->routes(__DIR__ . '/routes.php')
        ->listen($listen)
        ->withBanner(<<<'BANNER'
            Phalanx Server: Http realtime demo
            Listening on {url}

            Try:
            curl -i {url}/realtime/health
            curl -N {url}/realtime/counter
            curl -i {url}/realtime/somewhere -H 'Upgrade: websocket' -H 'Connection: Upgrade'
            BANNER)
        ->run();
};
