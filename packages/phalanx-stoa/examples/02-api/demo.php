<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\StoaDemo\Api\Bundle\ApiServiceBundle;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Phalanx\Stoa\OpenApi\OpenApiGenerator;
use Phalanx\Stoa\Stoa;

$routes = require __DIR__ . '/routes.php';

$app = Stoa::starting()
    ->providers(new ApiServiceBundle())
    ->routes($routes)
    ->build();

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
            'Authorization' => 'Bearer demo-token',
            'Idempotency-Key' => 'task-001',
            'Content-Type' => 'application/json',
        ]))->withBody(Utils::streamFor(json_encode([
            'title' => 'Review managed runtime claims',
            'priority' => 2,
        ], JSON_THROW_ON_ERROR))),
        201,
    ],
    'POST /api/v1/tasks (invalid body 422)' => [
        (new ServerRequest('POST', '/api/v1/tasks', [
            'Authorization' => 'Bearer demo-token',
            'Idempotency-Key' => 'task-002',
            'Content-Type' => 'application/json',
        ]))->withBody(Utils::streamFor(json_encode([
            'title' => 'no',
            'priority' => 9,
        ], JSON_THROW_ON_ERROR))),
        422,
    ],
];

$failed = false;

try {
    foreach ($checks as $label => [$request, $expected]) {
        $response = $app->dispatch($request);
        $status = $response->getStatusCode();
        $ok = $status === $expected;
        $failed = $failed || !$ok;

        printf("  %-6s %-3d  %s\n", $ok ? 'ok' : 'FAIL', $status, $label);
    }

    $spec = (new OpenApiGenerator(title: 'Stoa Demo API', version: '1.0.0'))->generate($routes);
    $paths = array_keys($spec['paths']);
    sort($paths);
    printf("\nOpenAPI generated paths: %s\n", implode(', ', $paths));
} finally {
    $app->shutdown();
}

exit($failed ? 1 : 0);
