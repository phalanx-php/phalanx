<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Acme\StoaDemo\Api\Bundle\ApiServiceBundle;
use Phalanx\Stoa\Stoa;

return static fn(array $context): \Closure => static function () use ($context): int {
    $listen = $context['argv'][1] ?? '127.0.0.1:8082';

    return Stoa::starting($context)
        ->providers(new ApiServiceBundle())
        ->routes(__DIR__ . '/routes.php')
        ->listen($listen)
        ->withBanner(<<<'BANNER'
            Phalanx Server: Stoa API demo
            Listening on {url}

            Try:
            curl -i {url}/api/v1/tasks/42
            curl -i {url}/api/v1/me -H 'Authorization: Bearer demo-token'
            curl -i -X POST {url}/api/v1/tasks \
              -H 'Authorization: Bearer demo-token' \
              -H 'Idempotency-Key: task-001' \
              -H 'Content-Type: application/json' \
              -d '{"title":"Review managed runtime claims","priority":2}'
            BANNER)
        ->run();
};
