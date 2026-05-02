<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Diagnostics;

use Phalanx\Diagnostics\EnvironmentDoctor;
use Phalanx\Supervisor\InProcessLedger;
use PHPUnit\Framework\TestCase;

final class EnvironmentDoctorTest extends TestCase
{
    public function testReportContainsRuntimeAndLedgerChecks(): void
    {
        $report = (new EnvironmentDoctor(new InProcessLedger()))->check();
        $checks = $report->toArray();

        self::assertTrue($report->isHealthy());
        self::assertContains('php.version', array_column($checks, 'name'));
        self::assertContains('openswoole.extension', array_column($checks, 'name'));
        self::assertContains('supervisor.ledger', array_column($checks, 'name'));
    }
}
