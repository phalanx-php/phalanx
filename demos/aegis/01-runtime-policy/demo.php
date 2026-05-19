<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Diagnostics\Severity;
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

                    $label = sprintf('%s — %s', $check->name, $check->detail);

                    match ($check->severity) {
                        Severity::Required      => $report->record($label, $check->ok),
                        Severity::Optional      => $report->note("{$label} [optional]"),
                        Severity::Informational => $report->note("{$label} [info]"),
                    };
                }
            },
        ));
    },
);
