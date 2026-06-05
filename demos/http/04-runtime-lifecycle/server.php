<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Acme\HttpDemo\Runtime\RuntimeLifecycleBundle;
use Phalanx\Http\Server;

return static fn(array $context): \Closure => static function () use ($context): int {
    $listen = $context['argv'][1] ?? '127.0.0.1:8083';

    return Server::starting($context)
        ->providers(new RuntimeLifecycleBundle())
        ->routes(__DIR__ . '/routes.php')
        ->listen($listen)
        ->withBanner(<<<'BANNER'
            Phalanx Server: Http runtime lifecycle demo
            Listening on {url}

            Try these examples:
            curl -i {url}/runtime/health
            curl -i {url}/runtime/slow
            curl -i --max-time 0.2 {url}/runtime/disconnect
            curl -i {url}/runtime/events

            After the disconnect example, inspect /runtime/events to see the cancellation and cleanup events.
            BANNER)
        ->run();
};
