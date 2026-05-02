<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Stoa\StoaRunner;

$app = Application::starting()->compile()->startup();
$runner = StoaRunner::from($app)->withRoutes(require __DIR__ . '/routes.php');

$checks = [
    ['GET', '/', 200],
    ['GET', '/users/42', 200],
    ['GET', '/posts/phalanx-stoa', 200],
    ['GET', '/users/not-an-int', 404],
    ['HEAD', '/users/42', 200],
];

$failed = false;

try {
    foreach ($checks as [$method, $path, $expected]) {
        $response = $runner->dispatch(new ServerRequest($method, $path));
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $ok = $status === $expected && ($method !== 'HEAD' || $body === '');
        $failed = $failed || !$ok;

        printf(
            "%s %s -> %d %s\n",
            $method,
            $path,
            $status,
            $ok ? 'ok' : 'failed',
        );
    }
} finally {
    $app->shutdown();
}

exit($failed ? 1 : 0);
