<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Demos\Kit\DemoApp;
use Phalanx\Demos\Kit\DemoReport;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

return DemoApp::boot(
    'Aegis Runtime Memory',
    static function (DemoApp $app, DemoReport $report): void {
        $app->run(Task::named(
            'demo.runtime-memory',
            static function (ExecutionScope $scope) use ($report): void {
                $memory = $scope->runtime->memory;
                $ids = [$memory->ids->next('demo'), $memory->ids->next('demo')];
                $resource = $memory->resources->open('demo.resource');
                $active = $memory->resources->activate($resource);
                $memory->resources->annotate($active, 'demo.route', 'runtime-memory');
                $memory->resources->recordEvent($active, 'demo.resource_touched');
                $memory->resources->close($active, 'demo_complete');

                $report->record('atomic ids advance', $ids === [1, 2]);
                $report->record('counter increments', $memory->counters->incr('demo.counter') === 1);
                $report->record('claim succeeds once', $memory->claims->claim('demo.claim', ttl: 1.0));
                $report->record('claim rejects duplicate', !$memory->claims->claim('demo.claim', ttl: 1.0));
                $report->record(
                    'resource annotation visible',
                    $memory->resources->annotation($resource->id, 'demo.route') === 'runtime-memory',
                );
                $report->record(
                    'resource terminal state visible',
                    (bool) $memory->resources->get($resource->id)?->state->isTerminal(),
                );
                $report->record('diagnostic events recorded', $memory->events->recent() !== []);

                $before = count($memory->events->recent());
                $cleared = $memory->events->clear();
                $after = count($memory->events->recent());
                $report->note(sprintf('events before clear: %d', $before));
                $report->note(sprintf('events cleared:      %d', $cleared));
                $report->note(sprintf('events after clear:  %d', $after));
                $report->record('events ring buffer cleared', $after === 0 && $cleared === $before);
            },
        ));
    },
);
