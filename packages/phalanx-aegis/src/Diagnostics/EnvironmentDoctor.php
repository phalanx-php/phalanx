<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\PostgreSQL;
use OpenSwoole\Table;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Supervisor\LedgerStorage;

final readonly class EnvironmentDoctor
{
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
            $checks[] = new DoctorCheck(
                'runtime.resources.live',
                true,
                (string) $this->memory->resources->liveCount(),
            );
            $checks[] = new DoctorCheck(
                'runtime.events.dropped',
                true,
                (string) $this->memory->counters->get('aegis.runtime.events.dropped'),
            );

            foreach ($this->memory->stats() as $stats) {
                $checks[] = new DoctorCheck(
                    'runtime.memory.' . $stats->name,
                    $stats->currentRows <= $stats->configuredRows,
                    sprintf(
                        '%d/%d rows, %d bytes, high-water %d',
                        $stats->currentRows,
                        $stats->configuredRows,
                        $stats->memorySize,
                        $stats->highWaterRows,
                    ),
                );
            }
        }

        return new DoctorReport($checks);
    }
}
