<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Application;
use Phalanx\Stoa\StoaRunner;

$listen = $argv[1] ?? '127.0.0.1:8080';
$app = Application::starting()->compile();
$exampleHost = str_starts_with($listen, '0.0.0.0:')
    ? '127.0.0.1:' . substr($listen, strlen('0.0.0.0:'))
    : $listen;
$baseUrl = "http://{$exampleHost}";

echo <<<BOOT
Phalanx Server: Stoa basic routing demo
Listening on http://{$listen}

Try these examples:
curl -i {$baseUrl}/
curl -i {$baseUrl}/users/42
curl -i {$baseUrl}/posts/phalanx-stoa
curl -I {$baseUrl}/users/42

BOOT;

StoaRunner::from($app)
    ->withRoutes(require __DIR__ . '/routes.php')
    ->run($listen);
