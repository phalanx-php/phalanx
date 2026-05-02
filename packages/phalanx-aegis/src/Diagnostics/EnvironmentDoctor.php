<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\PostgreSQL;
use OpenSwoole\Table;
use Phalanx\Supervisor\LedgerStorage;

final readonly class EnvironmentDoctor
{
    public function __construct(private ?LedgerStorage $ledger = null)
    {
    }

    public function check(): DoctorReport
    {
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
