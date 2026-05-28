<?php

declare(strict_types=1);

use AegisSwoole\Worker\WorkerRuntime;

$autoload = $argv[1] ?? __DIR__ . '/../vendor/autoload.php';
require $autoload;

WorkerRuntime::run($autoload);
