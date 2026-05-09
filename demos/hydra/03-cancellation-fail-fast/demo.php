<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Cancellation\Cancelled;
use Phalanx\Demos\Hydra\CancellationFailFast\FailingWorkerTask;
use Phalanx\Demos\Hydra\CancellationFailFast\SlowWorkerTask;
use Phalanx\Demos\Hydra\CancellationFailFast\WorkerHealthTask;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Hydra\Hydra;
use Phalanx\Hydra\ParallelConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return DemoApp::boot(
    'Hydra Cancellation and Fail-Fast',
    static function (DemoApp $app, DemoReport $report): void {
        $parentPid = getmypid();
        if ($parentPid === false) {
            throw new RuntimeException('Could not read parent process id.');
        }

        $timeoutMessage = $app->run(Task::named(
            'demo.hydra.cancellation-fail-fast.timeout',
            static function (ExecutionScope $scope): array {
                $started = microtime(true);
                try {
                    $scope->timeout(
                        0.05,
                        Task::of(static fn(ExecutionScope $child): mixed => $child->inWorker(
                            new SlowWorkerTask(500_000),
                        )),
                    );
                } catch (Cancelled $e) {
                    return [
                        'cancelled' => true,
                        'class'     => $e::class,
                        'elapsed'   => microtime(true) - $started,
                    ];
                }

                return [
                    'cancelled' => false,
                    'class'     => null,
                    'elapsed'   => microtime(true) - $started,
                ];
            },
        ));

        $afterTimeout = $app->run(Task::named(
            'demo.hydra.cancellation-fail-fast.after-timeout',
            static fn(ExecutionScope $scope): array => $scope->inWorker(new WorkerHealthTask('after-timeout')),
        ));

        $failFastMessage = $app->run(Task::named(
            'demo.hydra.cancellation-fail-fast.parallel',
            static function (ExecutionScope $scope): array {
                $started = microtime(true);
                $failFastMessage = null;
                try {
                    $scope->parallel(
                        slow: new SlowWorkerTask(500_000),
                        fail: new FailingWorkerTask('fail-fast failure'),
                    );
                } catch (\RuntimeException $e) {
                    $failFastMessage = $e->getMessage();
                }

                return [
                    'message' => $failFastMessage,
                    'elapsed' => microtime(true) - $started,
                ];
            },
        ));

        $afterFailFast = $app->run(Task::named(
            'demo.hydra.cancellation-fail-fast.after-fail-fast',
            static fn(ExecutionScope $scope): array => $scope->inWorker(new WorkerHealthTask('after-fail-fast')),
        ));

        $report->record(
            'timeout cancels in-flight worker dispatch',
            $timeoutMessage['cancelled'] === true
                && $timeoutMessage['class'] === Cancelled::class
                && $timeoutMessage['elapsed'] < 0.35,
        );
        $report->record('worker pool recovers after timeout', $afterTimeout['label'] === 'after-timeout');
        $report->record(
            'parallel propagates first worker failure',
            $failFastMessage['message'] === 'fail-fast failure'
                && $failFastMessage['elapsed'] < 0.35,
        );
        $report->record('worker pool recovers after fail-fast', $afterFailFast['label'] === 'after-fail-fast');
        $report->record('recovery work ran outside parent process', $afterFailFast['pid'] !== $parentPid);
        $report->record('task tree cleaned', $app->ledger()->liveTaskCount() === 0);
    },
    [Hydra::services(new ParallelConfig(agents: 2))],
);
