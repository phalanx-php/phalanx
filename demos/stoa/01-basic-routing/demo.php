<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Stoa\Stoa;

return DemoReport::demo(
    'Stoa Basic Routing',
    static function (DemoReport $report, AppContext $context): void {
        $app = Stoa::starting($context->values)
            ->routes(__DIR__ . '/routes.php')
            ->build();

        try {
            $checks = [
                ['GET',  '/',                    200],
                ['GET',  '/users/42',            200],
                ['GET',  '/posts/phalanx-stoa',  200],
                ['GET',  '/users/not-an-int',    404],
                ['HEAD', '/users/42',            200],
            ];

            foreach ($checks as [$method, $path, $expected]) {
                $response = $app->dispatch(new ServerRequest($method, $path));
                $status = $response->getStatusCode();
                $body = (string) $response->getBody();
                $ok = $status === $expected && ($method !== 'HEAD' || $body === '');
                $report->record(sprintf('%s %-20s -> %d', $method, $path, $status), $ok);
            }
        } finally {
            $app->shutdown();
        }
    },
);
