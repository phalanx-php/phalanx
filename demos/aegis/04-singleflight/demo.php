<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return DemoApp::boot(
    'Aegis Singleflight',
    static function (DemoApp $app, DemoReport $report): void {
        $owner = Task::named('demo.singleflight.owner', static function (ExecutionScope $owner): object {
            $owner->delay(0.02);

            return new \stdClass();
        });

        $waiters = array_map(
            static fn (int $i): Task => Task::named(
                "demo.singleflight.waiter.{$i}",
                static fn (ExecutionScope $w): object => $w->singleflight('demo:shared', $owner),
            ),
            range(0, 4),
        );

        $app->run(Task::named(
            'demo.singleflight.root',
            static function (ExecutionScope $root) use ($waiters, $report): void {
                $results = $root->concurrent(...$waiters);
                $objectIds = array_map(spl_object_id(...), $results);

                $report->record('all waiters share result', count(array_unique($objectIds)) === 1);
            },
        ));

        $report->record('supervisor cleaned', $app->ledger()->liveTaskCount() === 0);
    },
);
