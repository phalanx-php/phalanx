<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Demos\Worker\BasicWorkers\AddNumbersTask;
use Phalanx\Demos\Worker\BasicWorkers\GreetThroughServiceTask;
use Phalanx\Demos\Worker\BasicWorkers\WorkerDemoServiceBundle;
use Phalanx\Demos\Worker\BasicWorkers\ProcessIdentityTask;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Worker\Facade;
use Phalanx\Worker\ParallelConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return DemoApp::boot(
    'Worker Basic Workers',
    static function (DemoApp $app, DemoReport $report): void {
        $parentPid = getmypid();
        if ($parentPid === false) {
            throw new RuntimeException('Could not read parent process id.');
        }

        $result = $app->run(Task::named(
            'demo.worker.basic-workers',
            static function (ExecutionScope $scope) use ($parentPid): array {
                return [
                    'sum'      => $scope->inWorker(new AddNumbersTask(20, 22)),
                    'identity' => $scope->inWorker(new ProcessIdentityTask($parentPid)),
                    'greeting' => $scope->inWorker(new GreetThroughServiceTask('worker')),
                ];
            },
        ));

        $report->record('inWorker returns computed value', $result['sum'] === 42);
        $report->record(
            'work ran in a worker process',
            $result['identity']['parentPid'] === $parentPid
                && $result['identity']['workerPid'] !== $parentPid,
        );
        $report->record('worker can call parent service', $result['greeting'] === 'hello worker');
        $report->record('task tree cleaned', $app->ledger()->liveTaskCount() === 0);
    },
    [
        new WorkerDemoServiceBundle(),
        Facade::services(ParallelConfig::singleWorker()),
    ],
);
