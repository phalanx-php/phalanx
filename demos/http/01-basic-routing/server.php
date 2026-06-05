<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Http\Http;

return static fn(array $context): \Closure => static function () use ($context): int {
    $listen = $context['argv'][1] ?? '127.0.0.1:8188';

    return \Phalanx\Http\Server::starting($context)
        ->routes(__DIR__ . '/routes.php')
        ->listen($listen)
        ->withBanner(<<<'BANNER'
            Phalanx Server: Http basic routing demo
            Listening on {url}

            Try these examples:
            curl -i {url}/
            curl -i {url}/users/42
            curl -i {url}/posts/phalanx-http
            curl -I {url}/users/42
            BANNER)
        ->run();
};
