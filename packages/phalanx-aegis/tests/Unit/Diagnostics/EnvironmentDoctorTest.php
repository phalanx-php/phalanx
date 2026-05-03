<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Diagnostics;

use Phalanx\Diagnostics\EnvironmentDoctor;
use Phalanx\Runtime\Identity\AegisCounterSid;
use Phalanx\Runtime\Identity\AegisEventSid;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Supervisor\InProcessLedger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvironmentDoctorTest extends TestCase
{
    /**
     * @param list<array{name: string, ok: bool, detail: string}> $checks
     * @return array{name: string, ok: bool, detail: string}
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

    public function testMissingRequiredHooksMakeReportUnhealthy(): void
    {
        $policy = new RuntimePolicy(
            name: 'test:missing-hooks',
            requiredFlags: RuntimeHooks::currentFlags() | (1 << 30),
            sensitiveFlags: 0,
        );

        $report = (new EnvironmentDoctor(runtimePolicy: $policy))->check();
        $check = self::check($report->toArray(), 'openswoole.hooks.missing');

        self::assertFalse($report->isHealthy());
        self::assertFalse($check['ok']);
    }

    public function testReportContainsHealthyRuntimeMemoryChecks(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());

        try {
            $report = (new EnvironmentDoctor(memory: $memory))->check();
            $checks = $report->toArray();

            self::assertTrue($report->isHealthy());
            self::assertSame(
                'live=0, total=0, terminal=0, non_terminal=0',
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
            $memory->events->record(AegisEventSid::ResourceOpened);

            $report = (new EnvironmentDoctor(memory: $memory))->check();
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
            $memory->counters->incr(AegisCounterSid::RuntimeEventsDropped);

            $report = (new EnvironmentDoctor(memory: $memory))->check();
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
            $memory->resources->open(AegisResourceSid::Test, id: 'opening-resource');
            $memory->resources->open(
                AegisResourceSid::Test,
                id: 'active-resource',
                state: ManagedResourceState::Active,
            );
            $memory->resources->open(
                AegisResourceSid::Test,
                id: 'closed-resource',
                state: ManagedResourceState::Closed,
            );

            $report = (new EnvironmentDoctor(memory: $memory))->check();
            $checks = $report->toArray();

            self::assertSame(
                'live=2, total=3, terminal=1, non_terminal=2',
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
            $memory->resources->open(AegisResourceSid::Test, id: 'full-resource-table');

            $report = (new EnvironmentDoctor(memory: $memory))->check();
            $check = self::check($report->toArray(), 'runtime.memory.resources');

            self::assertFalse($report->isHealthy());
            self::assertFalse($check['ok']);
            self::assertStringContainsString('1/1 rows', $check['detail']);
            self::assertStringContainsString('high-water 1 (100.00%)', $check['detail']);
        } finally {
            $memory->shutdown();
        }
    }
}
