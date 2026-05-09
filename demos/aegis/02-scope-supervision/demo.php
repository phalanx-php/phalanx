<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return DemoApp::boot(
    'Aegis Scope Supervision',
    static function (DemoApp $app, DemoReport $report): void {
        $results = $app->run(Task::named(
            'demo.supervision.root',
            static function (ExecutionScope $root) use ($app): array {
                return $root->concurrent(
                    sleeper: Task::named(
                        'demo.supervision.sleeper',
                        static function (ExecutionScope $child): int {
                            $child->delay(0.02);
                            return 1;
                        },
                    ),
                    snapshot: Task::named(
                        'demo.supervision.snapshot',
                        static function (ExecutionScope $child) use ($app): array {
                            $child->delay(0.001);
                            return [
                                'value' => 2,
                                'tree'  => $app->ledger()->tree(),
                            ];
                        },
                    ),
                    fast: Task::named('demo.supervision.fast', static fn (): int => 3),
                );
            },
        ));

        $report->record(
            'concurrent result',
            $results['sleeper'] + $results['snapshot']['value'] + $results['fast'] === 6,
        );
        $report->record('task tree captured', str_contains($results['snapshot']['tree'], 'demo.supervision'));
        $report->note(rtrim($results['snapshot']['tree']));
        $report->record('supervisor cleaned', $app->ledger()->liveTaskCount() === 0);
    },
);
