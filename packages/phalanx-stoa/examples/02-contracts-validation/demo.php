<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Phalanx\Application;
use Phalanx\Stoa\StoaRunner;

$app = Application::starting()->compile()->startup();
$runner = StoaRunner::from($app)->withRoutes(require __DIR__ . '/routes.php');

$checks = [
    [
        (new ServerRequest('GET', '/tasks?owner=ops&limit=2'))
            ->withQueryParams(['owner' => 'ops', 'limit' => '2']),
        200,
    ],
    [
        (new ServerRequest('POST', '/tasks', [
            'Content-Type' => 'application/json',
            'Idempotency-Key' => 'task-001',
        ]))->withBody(Utils::streamFor(json_encode([
            'title' => 'Review Stoa route contracts',
            'priority' => 2,
        ], JSON_THROW_ON_ERROR))),
        201,
    ],
    [
        (new ServerRequest('POST', '/tasks', [
            'Content-Type' => 'application/json',
            'Idempotency-Key' => 'task-002',
        ]))->withBody(Utils::streamFor(json_encode([
            'title' => 'no',
            'priority' => 8,
        ], JSON_THROW_ON_ERROR))),
        422,
    ],
    [
        new ServerRequest('GET', '/tasks/1000'),
        422,
    ],
    [
        new ServerRequest('GET', '/tasks/not-an-int'),
        404,
    ],
];

$failed = false;

try {
    foreach ($checks as [$request, $expected]) {
        $response = $runner->dispatch($request);
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

exit($failed ? 1 : 0);
