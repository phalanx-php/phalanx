<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload_runtime.php';
require __DIR__ . '/BenchmarkCase.php';
require __DIR__ . '/cases/HttpCases.php';
require __DIR__ . '/cases/LazyCases.php';

use Phalanx\Benchmarks\Http\Cases\HttpDispatchDtoUnusedCase;
use Phalanx\Benchmarks\Http\Cases\HttpDispatchDtoUsedCase;
use Phalanx\Benchmarks\Http\Cases\HttpDispatchJsonCase;
use Phalanx\Benchmarks\Http\Cases\HttpDispatchPlaintextCase;
use Phalanx\Benchmarks\Http\Cases\HttpDispatchRouteParamCase;
use Phalanx\Benchmarks\Http\Cases\HttpDrainCleanupCase;
use Phalanx\Benchmarks\Http\Cases\HttpRequestFactoryCase;
use Phalanx\Benchmarks\Http\Cases\HttpRequestResourceLifecycleCase;
use Phalanx\Benchmarks\Kit\BenchmarkReport;
use Phalanx\Benchmarks\Kit\BenchmarkRunner;
use Phalanx\Boot\AppContext;

return BenchmarkRunner::boot('HTTP Benchmarks', static function (BenchmarkReport $report, AppContext $_context): void {
    $report->group([
        new HttpDispatchPlaintextCase(),
        new HttpDispatchJsonCase(),
        new HttpDispatchRouteParamCase(),
        new HttpRequestFactoryCase(),
        new HttpRequestResourceLifecycleCase(),
        new HttpDrainCleanupCase(),
        new HttpDispatchDtoUnusedCase(),
        new HttpDispatchDtoUsedCase(),
    ]);
});
