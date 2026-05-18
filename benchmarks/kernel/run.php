#!/usr/bin/env php
<?php

declare(strict_types=1);

use Phalanx\Benchmarks\Kernel\Cases\CancelSleepingChildrenCase;
use Phalanx\Benchmarks\Kernel\Cases\ConcurrentDelayCase;
use Phalanx\Benchmarks\Kernel\Cases\ConcurrentNoopCase;
use Phalanx\Benchmarks\Kernel\Cases\ConcurrentNoopUnpooledCase;
use Phalanx\Benchmarks\Kernel\Cases\ExecuteNoopTaskCase;
use Phalanx\Benchmarks\Kernel\Cases\ExecuteNoopTaskUnpooledCase;
use Phalanx\Benchmarks\Kernel\Cases\ExecuteStaticTaskOfCase;
use Phalanx\Benchmarks\Kernel\Cases\InProcessLedgerLifecycleCase;
use Phalanx\Benchmarks\Kernel\Cases\PhalanxManagedContextSwitchCase;
use Phalanx\Benchmarks\Kernel\Cases\RawFiberContextSwitchCase;
use Phalanx\Benchmarks\Kernel\Cases\RawSwooleContextSwitchCase;
use Phalanx\Benchmarks\Kernel\Cases\ScopeCreateDisposeCase;
use Phalanx\Benchmarks\Kernel\Cases\SingleflightWaitersCase;
use Phalanx\Benchmarks\Kernel\Cases\SupervisorLifecycleCase;
use Phalanx\Benchmarks\Kernel\Cases\SwooleTableLedgerLifecycleCase;
use Phalanx\Benchmarks\Kernel\Cases\SwooleTableLedgerProjectionCase;
use Phalanx\Benchmarks\Kernel\Cases\TraceLogChurnCase;
use Phalanx\Benchmarks\Kernel\Cases\TransactionScopeEnterExitCase;
use Phalanx\Benchmarks\Kit\BenchmarkReport;
use Phalanx\Benchmarks\Kit\BenchmarkRunner;
use Phalanx\Boot\AppContext;

require __DIR__ . '/../../vendor/autoload_runtime.php';
require __DIR__ . '/BenchmarkCase.php';
require __DIR__ . '/cases/CoreCases.php';
require __DIR__ . '/cases/ContextSwitchCases.php';

return BenchmarkRunner::boot('Aegis Kernel Benchmarks', static function (BenchmarkReport $report, AppContext $_context): void {
    $report->group([
        new ScopeCreateDisposeCase(),
        new ExecuteNoopTaskCase(),
        new ExecuteNoopTaskUnpooledCase(),
        new ExecuteStaticTaskOfCase(),
        new SupervisorLifecycleCase(),
        new TraceLogChurnCase(),
        new ConcurrentNoopCase(100),
        new ConcurrentNoopUnpooledCase(100),
        new ConcurrentDelayCase(100),
        new SingleflightWaitersCase(100),
        new CancelSleepingChildrenCase(100),
        new InProcessLedgerLifecycleCase(),
        new SwooleTableLedgerLifecycleCase(),
        new SwooleTableLedgerProjectionCase(),
        new TransactionScopeEnterExitCase(),
        new RawFiberContextSwitchCase(),
        new RawSwooleContextSwitchCase(),
        new PhalanxManagedContextSwitchCase(),
    ]);
});
