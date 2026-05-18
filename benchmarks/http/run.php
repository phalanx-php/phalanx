<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload_runtime.php';
require __DIR__ . '/BenchmarkCase.php';
require __DIR__ . '/cases/StoaCases.php';
require __DIR__ . '/cases/LazyCases.php';

use Phalanx\Benchmarks\Http\Cases\StoaDispatchDtoUnusedCase;
use Phalanx\Benchmarks\Http\Cases\StoaDispatchDtoUsedCase;
use Phalanx\Benchmarks\Http\Cases\StoaDispatchJsonCase;
use Phalanx\Benchmarks\Http\Cases\StoaDispatchPlaintextCase;
use Phalanx\Benchmarks\Http\Cases\StoaDispatchRouteParamCase;
use Phalanx\Benchmarks\Http\Cases\StoaDrainCleanupCase;
use Phalanx\Benchmarks\Http\Cases\StoaRequestFactoryCase;
use Phalanx\Benchmarks\Http\Cases\StoaRequestResourceLifecycleCase;
use Phalanx\Benchmarks\Kit\BenchmarkReport;
use Phalanx\Benchmarks\Kit\BenchmarkRunner;
use Phalanx\Boot\AppContext;

return BenchmarkRunner::boot('HTTP Benchmarks', static function (BenchmarkReport $report, AppContext $_context): void {
    $report->group([
        new StoaDispatchPlaintextCase(),
        new StoaDispatchJsonCase(),
        new StoaDispatchRouteParamCase(),
        new StoaRequestFactoryCase(),
        new StoaRequestResourceLifecycleCase(),
        new StoaDrainCleanupCase(),
        new StoaDispatchDtoUnusedCase(),
        new StoaDispatchDtoUsedCase(),
    ]);
});
