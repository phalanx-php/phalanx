#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Http;

use OpenSwoole\Coroutine;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Throwable;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/BenchmarkCase.php';
require __DIR__ . '/Runner.php';
require __DIR__ . '/cases/StoaCases.php';

$context = [
    'argv' => $argv ?? [],
];

RuntimeHooks::ensure(RuntimePolicy::fromContext($context));

$caught = null;
$exitCode = 0;

Coroutine::run(static function () use ($context, &$caught, &$exitCode): void {
    try {
        $exitCode = (new Runner($context['argv']))->run();
    } catch (Throwable $e) {
        $caught = $e;
        $exitCode = 1;
    }
});

if ($caught !== null) {
    fwrite(STDERR, $caught::class . ': ' . $caught->getMessage() . PHP_EOL);
    fwrite(STDERR, $caught->getTraceAsString() . PHP_EOL);
}

exit($exitCode);
