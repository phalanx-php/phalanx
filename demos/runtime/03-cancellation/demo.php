<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Cancellation\Cancelled;
use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return DemoApp::boot(
    'Runtime Cancellation',
    static function (DemoApp $app, DemoReport $report): void {
        $app->run(Task::named(
            'demo.cancellation.root',
            static function (ExecutionScope $root) use ($report): void {
                try {
                    $root->timeout(Mark::ms(10), Task::named(
                        'demo.cancellation.sleeper',
                        static function (ExecutionScope $child): void {
                            $child->delay(Mark::s(1));
                        },
                    ));
                    $message = 'not cancelled';
                } catch (Cancelled $e) {
                    $message = $e->getMessage();
                }

                $report->record('timeout raised cancellation', str_contains($message, 'timeout after'));
            },
        ));

        $report->record('supervisor cleaned', $app->ledger()->liveTaskCount() === 0);
    },
);
