<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Runtime\Identity\AegisAnnotationSid;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\RunState;
use Phalanx\Theatron\Reactor\ReactorGroup;
use Phalanx\Theatron\Store\StoreWriter;

final class AegisRuntimeStoreProjector
{
    public function __construct(
        private readonly RuntimeContext $runtime,
        private readonly StoreWriter $writer,
        private readonly ?ReactorGroup $reactorGroup = null,
    ) {
    }

    public function project(ExecutionScope $scope, int $frames): void
    {
        $query = $this->runtime->query;
        $events = $this->runtime->memory->events;

        $this->writer->update(
            RuntimeMetricsSlice::class,
            static fn(RuntimeMetricsSlice $metrics): RuntimeMetricsSlice => new RuntimeMetricsSlice(
                frames: $frames,
                handles: count($query->all()),
                tasks: count($query->all(AegisResourceSid::TaskRun)),
                events: count($events->recent()),
            ),
        );

        $runId = $scope->currentRunId();
        $runState = RunState::Running->value;

        if ($runId !== null) {
            $annotations = $query->annotations($runId);
            $runState = $annotations[AegisAnnotationSid::RunState->value()] ?? $runState;
        }

        $this->writer->update(
            RuntimeScopeSlice::class,
            static fn(RuntimeScopeSlice $slice): RuntimeScopeSlice => new RuntimeScopeSlice(
                currentRunId: $runId ?? '',
                currentRunState: $runState,
                activeScopes: count($query->all(AegisResourceSid::Scope)),
            ),
        );

        $memReal = memory_get_usage(true);
        $memZend = memory_get_usage(false);
        $memRealPeak = memory_get_peak_usage(true);
        $memZendPeak = memory_get_peak_usage(false);

        $this->writer->update(
            RuntimeMemorySlice::class,
            static fn(RuntimeMemorySlice $m): RuntimeMemorySlice => new RuntimeMemorySlice(
                memReal: $memReal,
                memZend: $memZend,
                memRealPeak: $memRealPeak,
                memZendPeak: $memZendPeak,
            ),
        );

        if ($this->reactorGroup !== null) {
            $states = $this->reactorGroup->states();

            $this->writer->update(
                ReactorStateSlice::class,
                static fn(ReactorStateSlice $s): ReactorStateSlice => new ReactorStateSlice(
                    reactors: $states,
                ),
            );
        }
    }
}
