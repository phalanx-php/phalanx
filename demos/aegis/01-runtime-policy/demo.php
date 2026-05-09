<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Task\Task;

return DemoApp::boot(
    'Aegis Runtime Policy',
    static function (DemoApp $app, DemoReport $report): void {
        // The doctor must run inside a scoped task body so OpenSwoole
        // runtime hooks are engaged; outside a coroutine they read as
        // disabled and the openswoole.* checks fail.
        $app->run(Task::named(
            'demo.runtime-policy',
            static function () use ($app, $report): void {
                foreach ($app->runtime()->report() as $check) {
                    if (
                        !str_starts_with($check->name, 'openswoole.')
                        && !str_starts_with($check->name, 'runtime.resources.')
                        && !str_starts_with($check->name, 'runtime.events.')
                        && !str_starts_with($check->name, 'runtime.memory.')
                    ) {
                        continue;
                    }

                    $report->record(sprintf('%s — %s', $check->name, $check->detail), $check->ok);
                }
            },
        ));
    },
);
