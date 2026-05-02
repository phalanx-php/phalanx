<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\PostgreSQL;
use OpenSwoole\Table;
use Phalanx\Runtime\RuntimeHookNames;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Supervisor\LedgerStorage;

final readonly class EnvironmentDoctor
{
    public function __construct(
        private ?LedgerStorage $ledger = null,
        private ?RuntimePolicy $runtimePolicy = null,
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
        $currentFlags = RuntimeHooks::currentFlags();
        $missingFlags = $policy->missingFlags($currentFlags);
        $sensitiveFlags = $policy->sensitiveEnabledFlags($currentFlags);

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
                $policy->name,
            ),
            new DoctorCheck(
                'openswoole.hook_flags',
                true,
                sprintf('%d (%s)', $currentFlags, self::formatNames(RuntimeHookNames::forMask($currentFlags))),
            ),
            new DoctorCheck(
                'openswoole.hooks.required',
                $missingFlags === 0,
                $missingFlags === 0
                    ? self::formatNames(RuntimeHookNames::forMask($policy->requiredFlags))
                    : 'missing: ' . self::formatNames(RuntimeHookNames::forMask($missingFlags)),
            ),
            new DoctorCheck(
                'openswoole.hooks.sensitive',
                true,
                self::formatNames(RuntimeHookNames::forMask($sensitiveFlags)),
            ),
        ];

        if ($this->ledger !== null) {
            $checks[] = new DoctorCheck(
                'supervisor.ledger',
                true,
                $this->ledger::class,
            );
        }

        return new DoctorReport($checks);
    }
}
