<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Acme\StoaDemo\Api\Bundle\ApiServiceBundle;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Stoa\OpenApi\OpenApiGenerator;
use Phalanx\Stoa\Stoa;

return DemoReport::demo(
    'Stoa API',
    static function (DemoReport $report, AppContext $context): void {
        $routes = require __DIR__ . '/routes.php';
        $app = Stoa::starting($context->values)
            ->providers(new ApiServiceBundle())
            ->routes($routes)
            ->build();

        try {
            $checks = [
                'GET /api/v1/tasks/42 (typed param 200)' => [
                    new ServerRequest('GET', '/api/v1/tasks/42'),
                    200,
                ],
                'GET /api/v1/tasks/9999 (param out of range)' => [
                    new ServerRequest('GET', '/api/v1/tasks/9999'),
                    422,
                ],
                'GET /api/v1/tasks/abc (non-int param)' => [
                    new ServerRequest('GET', '/api/v1/tasks/abc'),
                    404,
                ],
                'GET /api/v1/me with bearer (auth + middleware)' => [
                    new ServerRequest('GET', '/api/v1/me', ['Authorization' => 'Bearer demo-token']),
                    200,
                ],
                'POST /api/v1/tasks (validated input + auth)' => [
                    (new ServerRequest('POST', '/api/v1/tasks', [
                        'Authorization'   => 'Bearer demo-token',
                        'Idempotency-Key' => 'task-001',
                        'Content-Type'    => 'application/json',
                    ]))->withBody(Utils::streamFor(json_encode([
                        'title'    => 'Review managed runtime claims',
                        'priority' => 2,
                    ], JSON_THROW_ON_ERROR))),
                    201,
                ],
                'POST /api/v1/tasks (invalid body 422)' => [
                    (new ServerRequest('POST', '/api/v1/tasks', [
                        'Authorization'   => 'Bearer demo-token',
                        'Idempotency-Key' => 'task-002',
                        'Content-Type'    => 'application/json',
                    ]))->withBody(Utils::streamFor(json_encode([
                        'title'    => 'no',
                        'priority' => 9,
                    ], JSON_THROW_ON_ERROR))),
                    422,
                ],
            ];

            foreach ($checks as $label => [$request, $expected]) {
                $status = $app->dispatch($request)->getStatusCode();
                $report->record(sprintf('[%d] %s', $status, $label), $status === $expected);
            }

            $spec = (new OpenApiGenerator(title: 'Stoa Demo API', version: '1.0.0'))->generate($routes);
            $paths = array_keys($spec['paths']);
            sort($paths);
            $report->note('OpenAPI generated paths: ' . implode(', ', $paths));
        } finally {
            $app->shutdown();
        }
    },
);
