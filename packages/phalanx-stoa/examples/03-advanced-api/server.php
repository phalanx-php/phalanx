<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Acme\StoaDemo\Advanced\DemoServiceBundle;
use Phalanx\Application;
use Phalanx\Stoa\StoaRunner;

$listen = $argv[1] ?? '127.0.0.1:8082';
$app = Application::starting()
    ->providers(new DemoServiceBundle())
    ->compile();

echo "Stoa advanced API demo listening on http://{$listen}\n";

StoaRunner::from($app)
    ->withRoutes(require __DIR__ . '/routes.php')
    ->run($listen);
