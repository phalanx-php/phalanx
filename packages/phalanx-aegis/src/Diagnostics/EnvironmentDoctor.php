<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\PostgreSQL;
use OpenSwoole\Table;
use Phalanx\Runtime\Identity\AegisCounterSid;
use Phalanx\Runtime\Memory\ManagedResource;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeTableStats;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Supervisor\LedgerStorage;

final readonly class EnvironmentDoctor
{
    private const MEMORY_PRESSURE_RATIO = 0.9;

    public function __construct(
        private ?LedgerStorage $ledger = null,
        private ?RuntimePolicy $runtimePolicy = null,
        private ?RuntimeMemory $memory = null,
    ) {
    }

    /** @param list<string> $names */
    private static function formatNames(array $names): string
    {
        return $names === [] ? 'none' : implode(', ', $names);
    }

    /** @param array<string, int> $counts */
    private static function formatCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $name => $count) {
            $parts[] = "{$name}={$count}";
        }

        return implode(', ', $parts);
    }

    /** @return array<string, int> */
    private static function resourceStateCounts(ManagedResource ...$resources): array
    {
        $counts = [];
        foreach (ManagedResourceState::cases() as $state) {
            $counts[$state->value] = 0;
        }

        foreach ($resources as $resource) {
            $counts[$resource->state->value]++;
        }

        return $counts;
    }

    private static function terminalResourceCount(ManagedResource ...$resources): int
    {
        $terminal = 0;
        foreach ($resources as $resource) {
            if ($resource->state->isTerminal()) {
                $terminal++;
            }
        }

        return $terminal;
    }

    private static function listenerFailureDetail(int $count): string
    {
        return $count === 1 ? '1 failure' : "{$count} failures";
    }

    private static function droppedEventDetail(int $count): string
    {
        return $count === 1 ? '1 dropped event' : "{$count} dropped events";
    }

    private static function memoryPressureOk(RuntimeTableStats $stats): bool
    {
        if ($stats->currentRows > $stats->configuredRows) {
            return false;
        }

        return $stats->highWaterRows < (int) ceil($stats->configuredRows * self::MEMORY_PRESSURE_RATIO);
    }

    private static function memoryPressureDetail(RuntimeTableStats $stats): string
    {
        $highWaterPercent = $stats->configuredRows === 0
            ? 0.0
            : ($stats->highWaterRows / $stats->configuredRows) * 100;

        return sprintf(
            '%d/%d rows, %d bytes, high-water %d (%.2f%%)',
            $stats->currentRows,
            $stats->configuredRows,
            $stats->memorySize,
            $stats->highWaterRows,
            $highWaterPercent,
        );
    }

    public function check(): DoctorReport
    {
        $policy = $this->runtimePolicy ?? RuntimePolicy::phalanxManaged();
        $hooks = RuntimeHooks::inspect($policy);

        $checks = [
            new DoctorCheck(
                'php.version',
                version_compare(PHP_VERSION, '8.4.0', '>='),
                PHP_VERSION,
            ),
            new DoctorCheck(
                'openswoole.extension',
                extension_loaded('openswoole'),
                phpversion('openswoole') ?: 'not loaded',
            ),
            new DoctorCheck(
                'openswoole.coroutine',
                class_exists(Coroutine::class),
                Coroutine::class,
            ),
            new DoctorCheck(
                'openswoole.table',
                class_exists(Table::class),
                Table::class,
            ),
            new DoctorCheck(
                'openswoole.postgresql',
                class_exists(PostgreSQL::class),
                PostgreSQL::class,
            ),
            new DoctorCheck(
                'openswoole.runtime_policy',
                true,
                $hooks->policyName,
            ),
            new DoctorCheck(
                'openswoole.hook_flags',
                true,
                sprintf('%d (%s)', $hooks->currentFlags, self::formatNames($hooks->currentFlagNames())),
            ),
            new DoctorCheck(
                'openswoole.hooks.required',
                true,
                sprintf('%d (%s)', $hooks->requiredFlags, self::formatNames($hooks->requiredFlagNames())),
            ),
            new DoctorCheck(
                'openswoole.hooks.missing',
                $hooks->isHealthy(),
                sprintf('%d (%s)', $hooks->missingFlags, self::formatNames($hooks->missingFlagNames())),
            ),
            new DoctorCheck(
                'openswoole.hooks.sensitive',
                true,
                sprintf(
                    '%d (%s)',
                    $hooks->sensitiveEnabledFlags,
                    self::formatNames($hooks->sensitiveEnabledFlagNames()),
                ),
            ),
        ];

        if ($this->ledger !== null) {
            $checks[] = new DoctorCheck(
                'supervisor.ledger',
                true,
                $this->ledger::class,
            );
        }

        if ($this->memory !== null) {
            $resources = $this->memory->resources->all();
            $totalResources = count($resources);
            $terminalResources = self::terminalResourceCount(...$resources);
            $liveResources = $totalResources - $terminalResources;

            $checks[] = new DoctorCheck(
                'runtime.resources.live',
                true,
                sprintf(
                    'live=%d, total=%d, terminal=%d, non_terminal=%d',
                    $liveResources,
                    $totalResources,
                    $terminalResources,
                    $liveResources,
                ),
            );
            $checks[] = new DoctorCheck(
                'runtime.resources.states',
                true,
                self::formatCounts(self::resourceStateCounts(...$resources)),
            );

            $listenerFailures = count($this->memory->events->listenerErrors());
            $checks[] = new DoctorCheck(
                'runtime.events.listener_failures',
                $listenerFailures === 0,
                self::listenerFailureDetail($listenerFailures),
            );

            $droppedEvents = $this->memory->counters->get(AegisCounterSid::RuntimeEventsDropped);
            $checks[] = new DoctorCheck(
                'runtime.events.dropped',
                $droppedEvents === 0,
                self::droppedEventDetail($droppedEvents),
            );

            foreach ($this->memory->stats() as $stats) {
                $checks[] = new DoctorCheck(
                    'runtime.memory.' . $stats->name,
                    self::memoryPressureOk($stats),
                    self::memoryPressureDetail($stats),
                );
            }
        }

        return new DoctorReport($checks);
    }
}
