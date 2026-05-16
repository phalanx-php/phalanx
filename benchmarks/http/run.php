<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload_runtime.php';
require __DIR__ . '/BenchmarkCase.php';
require __DIR__ . '/cases/StoaCases.php';
require __DIR__ . '/cases/LazyCases.php';

use Phalanx\Benchmarks\Kit\BenchmarkReport;
use Phalanx\Benchmarks\Kit\BenchmarkRunner;
use Phalanx\Boot\AppContext;

use function Phalanx\Benchmarks\Http\Cases\lazyHttpCases;
use function Phalanx\Benchmarks\Http\Cases\stoaHttpCases;

return BenchmarkRunner::boot('HTTP Benchmarks', static function (BenchmarkReport $report, AppContext $context): void {
    $report->group(stoaHttpCases());
    $report->group(lazyHttpCases());
});
