<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Application;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;

$context = [
    'argv' => $argv ?? [],
    RuntimeMemoryConfig::CONTEXT_KEY => [
        'resource_rows' => 64,
        'edge_rows' => 64,
        'lease_rows' => 64,
        'annotation_rows' => 64,
        'event_rows' => 64,
        'counter_rows' => 64,
        'claim_rows' => 64,
        'symbol_rows' => 64,
    ],
];

$failed = Application::starting($context)
    ->run(Task::named(
        'demo.runtime-memory',
        static function (ExecutionScope $scope): bool {
            $memory = $scope->runtime->memory;
            $ids = [
                $memory->ids->next('demo'),
                $memory->ids->next('demo'),
            ];
            $resource = $memory->resources->open('demo.resource');
            $active = $memory->resources->activate($resource);
            $memory->resources->annotate($active, 'demo.route', 'runtime-memory');
            $memory->resources->recordEvent($active, 'demo.resource_touched');
            $memory->resources->close($active, 'demo_complete');

            $checks = [
                'atomic ids advance' => $ids === [1, 2],
                'counter increments' => $memory->counters->incr('demo.counter') === 1,
                'claim succeeds once' => $memory->claims->claim('demo.claim', ttl: 1.0),
                'claim rejects duplicate' => !$memory->claims->claim('demo.claim', ttl: 1.0),
                'resource annotation visible' => $memory->resources->annotation($resource->id, 'demo.route') === 'runtime-memory',
                'resource terminal state visible' => $memory->resources->get($resource->id)?->state->isTerminal(),
                'diagnostic events recorded' => $memory->events->recent() !== [],
            ];

            $failed = false;
            foreach ($checks as $label => $ok) {
                $failed = $failed || !$ok;
                printf("%s -> %s\n", $label, $ok ? 'ok' : 'failed');
            }

            // Lifecycle event ring buffer: persistent within the process,
            // FIFO-evicted at capacity. clear() truncates explicitly so
            // a subsequent recent() call gives a fresh view.
            $before = count($memory->events->recent());
            $cleared = $memory->events->clear();
            $after = count($memory->events->recent());
            printf("events before clear -> %d\n", $before);
            printf("events cleared      -> %d\n", $cleared);
            printf("events after clear  -> %d\n", $after);
            $failed = $failed || $after !== 0 || $cleared !== $before;

            return $failed;
        },
    ));

exit($failed ? 1 : 0);
