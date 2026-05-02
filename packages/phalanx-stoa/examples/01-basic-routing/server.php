<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Phalanx\Application;
use Phalanx\Stoa\StoaRunner;

$listen = $argv[1] ?? '127.0.0.1:8080';
$app = Application::starting()->compile();

echo "Stoa basic routing demo listening on http://{$listen}\n";

StoaRunner::from($app)
    ->withRoutes(require __DIR__ . '/routes.php')
    ->run($listen);
