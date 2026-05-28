<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

use Phalanx\Runtime\Identity\AegisCounterSid;
use Phalanx\Runtime\Memory\ManagedResource;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeTableStats;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Supervisor\Supervisor;
use Swoole\Coroutine\PostgreSQL;
use Swoole\Table;

final class EnvironmentDoctor
{
    private const float MEMORY_PRESSURE_RATIO = 0.9;

    public function __construct(
        private ?LedgerStorage $ledger = null,
        private ?RuntimePolicy $runtimePolicy = null,
        private ?RuntimeMemory $memory = null,
        private ?Supervisor $supervisor = null,
    ) {
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
                'swoole.extension',
                extension_loaded('swoole') || extension_loaded('openswoole'),
                phpversion('swoole') ?: phpversion('openswoole') ?: 'not loaded',
            ),
            new DoctorCheck(
                'swoole.coroutine',
                extension_loaded('swoole') || extension_loaded('openswoole'),
                extension_loaded('swoole') ? 'Swoole\Coroutine' : (extension_loaded('openswoole') ? 'OpenSwoole\Coroutine' : 'not loaded'),
            ),
            new DoctorCheck(
                'swoole.table',
                class_exists(Table::class),
                Table::class,
            ),
            // PostgreSQL coroutine support is optional; core Phalanx does not require it.
            new DoctorCheck(
                'swoole.postgresql',
                class_exists(PostgreSQL::class),
                PostgreSQL::class,
                Severity::Optional,
            ),
            // Policy name and flag details are diagnostic context — always ok=true, never block health.
            new DoctorCheck(
                'swoole.runtime_policy',
                true,
                $hooks->policyName,
                Severity::Informational,
            ),
            new DoctorCheck(
                'swoole.hook_flags',
                true,
                sprintf('%d (%s)', $hooks->currentFlags, self::formatNames($hooks->currentFlagNames())),
                Severity::Informational,
            ),
            new DoctorCheck(
                'swoole.hooks.required',
                true,
                sprintf('%d (%s)', $hooks->requiredFlags, self::formatNames($hooks->requiredFlagNames())),
                Severity::Informational,
            ),
            // Missing required hooks means the hook policy is broken — the runtime cannot
            // deliver coroutine-safe I/O to application code.
            new DoctorCheck(
                'swoole.hooks.missing',
                $hooks->isHealthy(),
                sprintf('%d (%s)', $hooks->missingFlags, self::formatNames($hooks->missingFlagNames())),
            ),
            // Sensitive flag reporting is diagnostic context only.
            new DoctorCheck(
                'swoole.hooks.sensitive',
                true,
                sprintf(
                    '%d (%s)',
                    $hooks->sensitiveEnabledFlags,
                    self::formatNames($hooks->sensitiveEnabledFlagNames()),
                ),
                Severity::Informational,
            ),
        ];

        if ($this->ledger !== null) {
            // Which ledger backend is active is diagnostic context, not a health gate.
            $checks[] = new DoctorCheck(
                'supervisor.ledger',
                true,
                $this->ledger::class,
                Severity::Informational,
            );
        }

        if ($this->supervisor !== null) {
            // Pool stats are diagnostic context — borrowed/free counts inform capacity planning
            // but never indicate a fault on their own.
            foreach ($this->supervisor->poolStats()->toArray() as $name => $stats) {
                $checks[] = new DoctorCheck(
                    "supervisor.pool.{$name}",
                    true,
                    self::poolStatsDetail($stats),
                    Severity::Informational,
                );
            }
        }

        if ($this->memory !== null) {
            $resources = $this->memory->resources->all();
            $totalResources = count($resources);
            $terminalResources = self::terminalResourceCount(...$resources);
            $liveResources = $totalResources - $terminalResources;

            // Resource live/state counts are diagnostic context.
            $checks[] = new DoctorCheck(
                'runtime.resources.live',
                true,
                sprintf(
                    'live=%d, total=%d, terminal=%d',
                    $liveResources,
                    $totalResources,
                    $terminalResources,
                ),
                Severity::Informational,
            );
            $checks[] = new DoctorCheck(
                'runtime.resources.states',
                true,
                self::formatCounts(self::resourceStateCounts(...$resources)),
                Severity::Informational,
            );

            // Listener failures and dropped events both indicate broken runtime event bus — Required.
            $listenerFailures = count($this->memory->events->listenerErrors());
            $checks[] = new DoctorCheck(
                'runtime.events.listener_failures',
                $listenerFailures === 0,
                self::listenerFailureDetail($listenerFailures),
            );

            // Dropped runtime events are sticky because the counter is monotonic.
            $droppedEvents = $this->memory->counters->get(AegisCounterSid::RuntimeEventsDropped);
            $checks[] = new DoctorCheck(
                'runtime.events.dropped',
                $droppedEvents === 0,
                self::droppedEventDetail($droppedEvents),
            );

            // Table overflow means the runtime will start losing data — Required.
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

    /** @param array<string, int> $stats */
    private static function poolStatsDetail(array $stats): string
    {
        $parts = [];
        foreach ($stats as $name => $value) {
            $parts[] = "{$name}={$value}";
        }

        return implode(', ', $parts);
    }
}
