<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\StoaDemo\Advanced\DemoServiceBundle;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Phalanx\Stoa\OpenApi\OpenApiGenerator;
use Phalanx\Stoa\Stoa;

$routes = require __DIR__ . '/routes.php';
$app = Stoa::starting()
    ->providers(new DemoServiceBundle())
    ->routes($routes)
    ->build();

$checks = [
    [
        new ServerRequest('GET', '/api/v1/health'),
        200,
    ],
    [
        new ServerRequest('GET', '/api/v1/reports/2026/05'),
        200,
    ],
    [
        new ServerRequest('GET', '/api/v1/reports/2026/13'),
        422,
    ],
    [
        new ServerRequest('GET', '/api/v1/admin/me', ['Authorization' => 'Bearer demo-token']),
        200,
    ],
    [
        (new ServerRequest('POST', '/api/v1/admin/jobs', [
            'Authorization' => 'Bearer demo-token',
            'Content-Type' => 'application/json',
        ]))->withBody(Utils::streamFor(json_encode([
            'name' => 'nightly-ledger-rollup',
            'priority' => 4,
        ], JSON_THROW_ON_ERROR))),
        202,
    ],
];

$failed = false;

try {
    foreach ($checks as [$request, $expected]) {
        $response = $app->dispatch($request);
        $status = $response->getStatusCode();
        $ok = $status === $expected;
        $failed = $failed || !$ok;

        printf(
            "%s %s -> %d %s\n",
            $request->getMethod(),
            $request->getUri()->getPath(),
            $status,
            $ok ? 'ok' : 'failed',
        );
    }
} finally {
    $app->shutdown();
}

$spec = (new OpenApiGenerator(
    title: 'Stoa Advanced Demo API',
    version: '1.0.0',
))->generate($routes);

printf("OpenAPI paths: %s\n", implode(', ', array_keys($spec['paths'])));

exit($failed ? 1 : 0);
