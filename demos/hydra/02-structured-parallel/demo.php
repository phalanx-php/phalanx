<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Demos\Hydra\StructuredParallel\FailingWorkerTask;
use Phalanx\Demos\Hydra\StructuredParallel\SumRangeTask;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Hydra\Hydra;
use Phalanx\Hydra\ParallelConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Worker\WorkerTask;

return DemoApp::boot(
    'Hydra Structured Parallel',
    static function (DemoApp $app, DemoReport $report): void {
        $parentPid = getmypid();
        if ($parentPid === false) {
            throw new RuntimeException('Could not read parent process id.');
        }

        $result = $app->run(Task::named(
            'demo.hydra.structured-parallel',
            static function (ExecutionScope $scope): array {
                $parallel = $scope->parallel(
                    left: new SumRangeTask(1, 50),
                    right: new SumRangeTask(51, 100),
                );

                $settled = $scope->settleParallel(
                    ok: new SumRangeTask(1, 10),
                    fail: new FailingWorkerTask('demo failure'),
                );

                $seen = [];
                $mapped = $scope->mapParallel(
                    [
                        'a' => [1, 25],
                        'b' => [26, 50],
                        'c' => [51, 75],
                        'd' => [76, 100],
                    ],
                    static fn(array $range): WorkerTask => new SumRangeTask($range[0], $range[1]),
                    limit: 2,
                    onEach: static function (string|int $key, mixed $value) use (&$seen): void {
                        $seen[$key] = $value;
                    },
                );

                return [
                    'parallel' => $parallel,
                    'settled'  => [
                        'ok'       => $settled->get('ok'),
                        'error'    => $settled->errors['fail']->getMessage() ?? null,
                        'okKeys'   => $settled->okKeys,
                        'errKeys'  => $settled->errKeys,
                    ],
                    'mapped'   => $mapped,
                    'seen'     => $seen,
                ];
            },
        ));

        $parallelSums = [
            'left'  => $result['parallel']['left']['sum'],
            'right' => $result['parallel']['right']['sum'],
        ];
        $mappedSums = array_map(
            static fn(array $chunk): int => $chunk['sum'],
            $result['mapped'],
        );
        $mappedTotal = array_sum(array_map(
            static fn(array $chunk): int => $chunk['sum'],
            $result['mapped'],
        ));
        $seenKeys = array_keys($result['seen']);
        sort($seenKeys);

        $report->record('parallel preserves exact keyed worker results', $parallelSums === [
            'left'  => 1275,
            'right' => 3775,
        ]);
        $report->record(
            'parallel used real worker processes',
            $result['parallel']['left']['pid'] !== $parentPid
                && $result['parallel']['right']['pid'] !== $parentPid,
        );
        $report->record('settleParallel preserves successful value', $result['settled']['ok']['sum'] === 55);
        $report->record('settleParallel captures worker failure', $result['settled']['error'] === 'demo failure');
        $report->record(
            'settleParallel partitions keys',
            $result['settled']['okKeys'] === ['ok'] && $result['settled']['errKeys'] === ['fail'],
        );
        $report->record(
            'mapParallel preserves exact keyed chunk results',
            $mappedSums === [
                'a' => 325,
                'b' => 950,
                'c' => 1575,
                'd' => 2200,
            ] && $mappedTotal === 5050,
        );
        $report->record('mapParallel onEach saw every result', $seenKeys === ['a', 'b', 'c', 'd']);
        $report->record('task tree cleaned', $app->ledger()->liveTaskCount() === 0);
    },
    [Hydra::services(new ParallelConfig(agents: 2))],
);
