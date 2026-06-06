<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Diagnostics;

use Phalanx\Diagnostics\DoctorCheck;
use Phalanx\Diagnostics\DoctorReport;
use Phalanx\Diagnostics\EnvironmentDoctor;
use Phalanx\Diagnostics\Severity;
use Phalanx\Runtime\Identity\RuntimeCounterSid;
use Phalanx\Runtime\Identity\RuntimeEventSid;
use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Runtime\RuntimeCapability;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Runtime\SwooleHook;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Task\Task;
use Phalanx\Trace\Trace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvironmentDoctorTest extends TestCase
{
    public function testReportContainsRuntimeAndLedgerChecks(): void
    {
        $policy = RuntimePolicy::phalanxManaged();
        RuntimeHooks::ensure($policy);

        $report = new EnvironmentDoctor(new InProcessLedger(), $policy)->check();
        $checks = $report->toArray();

        self::assertSame(self::runtimeChecksAreHealthy($policy), $report->isHealthy());
        self::assertContains('php.version', array_column($checks, 'name'));
        self::assertContains('swoole.extension', array_column($checks, 'name'));
        self::assertContains('swoole.runtime_policy', array_column($checks, 'name'));
        self::assertContains('swoole.hook_flags', array_column($checks, 'name'));
        self::assertContains('swoole.hooks.required', array_column($checks, 'name'));
        self::assertContains('swoole.hooks.missing', array_column($checks, 'name'));
        self::assertContains('swoole.hooks.unavailable_required', array_column($checks, 'name'));
        self::assertContains('swoole.hooks.sensitive', array_column($checks, 'name'));
        self::assertContains('supervisor.ledger', array_column($checks, 'name'));
    }

    public function testReportContainsInformationalSupervisorPoolChecks(): void
    {
        $supervisor = new Supervisor(new InProcessLedger(), new Trace());
        $report = new EnvironmentDoctor(supervisor: $supervisor)->check();
        $checks = $report->toArray();

        self::assertSame(self::runtimeChecksAreHealthy(), $report->isHealthy());
        self::assertTrue(self::check($checks, 'supervisor.pool.taskRun')['ok']);
        self::assertTrue(self::check($checks, 'supervisor.pool.token')['ok']);
        self::assertStringContainsString('borrowed=0', self::check($checks, 'supervisor.pool.taskRun')['detail']);
        self::assertStringContainsString('free=0', self::check($checks, 'supervisor.pool.token')['detail']);
    }

    public function testSupervisorPoolChecksReportBorrowedTaskRuns(): void
    {
        $supervisor = new Supervisor(new InProcessLedger(), new Trace());
        $scope = $this->createStub(ExecutionScope::class);
        $task = Task::named('doctor.borrowed', static fn(ExecutionScope $_scope): null => null);
        $run = $supervisor->start($task, $scope, DispatchMode::Inline);

        try {
            $report = new EnvironmentDoctor(supervisor: $supervisor)->check();
            $check = self::check($report->toArray(), 'supervisor.pool.taskRun');

            self::assertTrue($check['ok']);
            self::assertStringContainsString('borrowed=1', $check['detail']);
        } finally {
            $supervisor->complete($run, null);
            $supervisor->reap($run);
        }
    }

    public function testMissingRequiredHooksMakeReportUnhealthy(): void
    {
        $policy = new RuntimePolicy(
            name: 'test:missing-hooks',
            requiredFlags: RuntimeHooks::currentFlags() | SwooleHook::Sleep->value,
            sensitiveFlags: 0,
        );

        $report = new EnvironmentDoctor(runtimePolicy: $policy)->check();
        $check = self::check($report->toArray(), 'swoole.hooks.missing');

        self::assertFalse($report->isHealthy());
        self::assertFalse($check['ok']);
    }

    public function testUnavailableRequiredHooksMakeReportUnhealthy(): void
    {
        $policy = RuntimePolicy::forCapabilities(RuntimeCapability::PdoPgsql);
        $report = new EnvironmentDoctor(runtimePolicy: $policy)->check();
        $check = self::check($report->toArray(), 'swoole.hooks.unavailable_required');

        self::assertSame(!SwooleHook::PdoPgsql->isAvailable(), !$check['ok']);
        if (!SwooleHook::PdoPgsql->isAvailable()) {
            self::assertFalse($report->isHealthy());
            self::assertStringContainsString('PDO_PGSQL', $check['detail']);
        }
    }

    public function testReportContainsHealthyRuntimeMemoryChecks(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());

        try {
            $report = new EnvironmentDoctor(memory: $memory)->check();
            $checks = $report->toArray();

            self::assertSame(self::runtimeChecksAreHealthy(), $report->isHealthy());
            self::assertSame(
                'live=0, total=0, terminal=0',
                self::check($checks, 'runtime.resources.live')['detail'],
            );
            self::assertSame(
                'opening=0, active=0, closing=0, closed=0, aborting=0, aborted=0, failing=0, failed=0',
                self::check($checks, 'runtime.resources.states')['detail'],
            );
            self::assertSame('0 failures', self::check($checks, 'runtime.events.listener_failures')['detail']);
            self::assertSame('0 dropped events', self::check($checks, 'runtime.events.dropped')['detail']);
            self::assertTrue(self::check($checks, 'runtime.memory.resources')['ok']);
        } finally {
            $memory->shutdown();
        }
    }

    public function testListenerFailuresMakeRuntimeMemoryReportUnhealthy(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());

        try {
            $memory->events->listen(static function (): void {
                throw new RuntimeException('listener failed');
            });
            $memory->events->record(RuntimeEventSid::ResourceOpened);

            $report = new EnvironmentDoctor(memory: $memory)->check();
            $check = self::check($report->toArray(), 'runtime.events.listener_failures');

            self::assertFalse($report->isHealthy());
            self::assertFalse($check['ok']);
            self::assertSame('1 failure', $check['detail']);
        } finally {
            $memory->shutdown();
        }
    }

    public function testDroppedEventsMakeRuntimeMemoryReportUnhealthy(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());

        try {
            $memory->counters->incr(RuntimeCounterSid::RuntimeEventsDropped);

            $report = new EnvironmentDoctor(memory: $memory)->check();
            $check = self::check($report->toArray(), 'runtime.events.dropped');

            self::assertFalse($report->isHealthy());
            self::assertFalse($check['ok']);
            self::assertSame('1 dropped event', $check['detail']);
        } finally {
            $memory->shutdown();
        }
    }

    public function testResourceStateSummaryUsesManagedResourceTruth(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());

        try {
            $memory->resources->open(RuntimeResourceSid::Test, id: 'opening-resource');
            $memory->resources->open(
                RuntimeResourceSid::Test,
                id: 'active-resource',
                state: ManagedResourceState::Active,
            );
            $memory->resources->open(
                RuntimeResourceSid::Test,
                id: 'closed-resource',
                state: ManagedResourceState::Closed,
            );

            $report = new EnvironmentDoctor(memory: $memory)->check();
            $checks = $report->toArray();

            self::assertSame(
                'live=2, total=3, terminal=1',
                self::check($checks, 'runtime.resources.live')['detail'],
            );
            self::assertStringContainsString('opening=1', self::check($checks, 'runtime.resources.states')['detail']);
            self::assertStringContainsString('active=1', self::check($checks, 'runtime.resources.states')['detail']);
            self::assertStringContainsString('closed=1', self::check($checks, 'runtime.resources.states')['detail']);
        } finally {
            $memory->shutdown();
        }
    }

    public function testMemoryPressureMakesReportUnhealthy(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig(resourceRows: 1));

        try {
            $memory->resources->open(RuntimeResourceSid::Test, id: 'full-resource-table');

            $report = new EnvironmentDoctor(memory: $memory)->check();
            $check = self::check($report->toArray(), 'runtime.memory.resources');

            self::assertFalse($report->isHealthy());
            self::assertFalse($check['ok']);
            self::assertStringContainsString('1/1 rows', $check['detail']);
            self::assertStringContainsString('high-water 1 (100.00%)', $check['detail']);
        } finally {
            $memory->shutdown();
        }
    }

    public function testBaselineReportDoesNotEmitLegacyPostgresqlProbe(): void
    {
        $pgCheck = self::findCheck(new EnvironmentDoctor()->check(), 'swoole.postgresql');

        self::assertNull($pgCheck, 'swoole.postgresql is a stale Swoole 6 diagnostic and should not be emitted.');
    }

    public function testCoroutineProbeCarriesRequiredSeverity(): void
    {
        $coroutineCheck = self::findCheck(new EnvironmentDoctor()->check(), 'swoole.coroutine');

        self::assertNotNull($coroutineCheck, 'swoole.coroutine probe not found in report');
        self::assertSame(Severity::Required, $coroutineCheck->severity);
    }

    #[Test]
    public function freshDoctorEmitsAtLeastOneRequiredProbe(): void
    {
        $doctor = new EnvironmentDoctor();
        $report = $doctor->check();

        $requiredProbes = array_filter(
            $report->checks,
            static fn(DoctorCheck $check): bool => $check->severity === Severity::Required,
        );

        self::assertNotEmpty(
            $requiredProbes,
            'EnvironmentDoctor must emit at least one Required probe; without it, isHealthy() would be vacuously true.',
        );
    }

    /**
     * @param list<array{name: string, ok: bool, detail: string, severity: string}> $checks
     * @return array{name: string, ok: bool, detail: string, severity: string}
     */
    private static function check(array $checks, string $name): array
    {
        foreach ($checks as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }

        self::fail("Missing doctor check {$name}.");
    }

    private static function findCheck(DoctorReport $report, string $name): ?DoctorCheck
    {
        foreach ($report as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        return null;
    }

    private static function runtimeChecksAreHealthy(?RuntimePolicy $policy = null): bool
    {
        return extension_loaded('swoole') && RuntimeHooks::inspect($policy ?? RuntimePolicy::phalanxManaged())->isHealthy();
    }
}
