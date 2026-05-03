<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Diagnostics;

use Phalanx\Diagnostics\EnvironmentDoctor;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Supervisor\InProcessLedger;
use PHPUnit\Framework\TestCase;

final class EnvironmentDoctorTest extends TestCase
{
    public function testReportContainsRuntimeAndLedgerChecks(): void
    {
        $policy = RuntimePolicy::phalanxManaged();
        RuntimeHooks::ensure($policy);

        $report = (new EnvironmentDoctor(new InProcessLedger(), $policy))->check();
        $checks = $report->toArray();

        self::assertTrue($report->isHealthy());
        self::assertContains('php.version', array_column($checks, 'name'));
        self::assertContains('openswoole.extension', array_column($checks, 'name'));
        self::assertContains('openswoole.runtime_policy', array_column($checks, 'name'));
        self::assertContains('openswoole.hook_flags', array_column($checks, 'name'));
        self::assertContains('openswoole.hooks.required', array_column($checks, 'name'));
        self::assertContains('openswoole.hooks.missing', array_column($checks, 'name'));
        self::assertContains('openswoole.hooks.sensitive', array_column($checks, 'name'));
        self::assertContains('supervisor.ledger', array_column($checks, 'name'));
    }
}
