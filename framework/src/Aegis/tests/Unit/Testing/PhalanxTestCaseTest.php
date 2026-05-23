<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing;

use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\ScopeIdentity;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestExpectations;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

class PhalanxTestCaseTest extends PhalanxTestCase
{
    public function testScopeRunCreatesFreshManagedScopeForEachRun(): void
    {
        $scopeIds = [];

        $first = $this->scope->run(static function (ExecutionScope $scope) use (&$scopeIds): int {
            self::assertInstanceOf(ScopeIdentity::class, $scope);
            $scopeIds[] = $scope->scopeId;

            return $scope->execute(Task::of(static fn(): int => 42));
        });

        $second = $this->scope->run(static function (ExecutionScope $scope) use (&$scopeIds): int {
            self::assertInstanceOf(ScopeIdentity::class, $scope);
            $scopeIds[] = $scope->scopeId;

            return $scope->execute(Task::of(static fn(): int => 84));
        });

        self::assertSame(42, $first);
        self::assertSame(84, $second);
        self::assertCount(2, array_unique($scopeIds));
        $this->scope->expect->runtime()->clean();
    }

    public function testScopeRunRequiresStaticClosureBodies(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Phalanx test scope bodies must be static closures.');

        $this->scope->run(function (): void {
        });
    }

    public function testExpectationGrammarReportsCleanRuntimeState(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $scope->execute(Task::of(static fn(): string => 'ok'));
        });

        $this->scope->expect->scope()->disposed();
        $this->scope->expect->work()->finished();
        $this->scope->expect->handles()->closed();
        $this->scope->expect->leases()->released();
        $this->scope->expect->diagnostics()->healthy();
        $this->scope->expect->runtime()->clean();
    }

    public function testExpectationGrammarSeesManualResourceLeaksBeforeTeardown(): void
    {
        $handle = $this->scope->memory->resources->open(AegisResourceSid::Test, id: 'leaky-test-resource');
        $active = $this->scope->memory->resources->activate($handle);

        self::assertSame(1, $this->scope->memory->resources->liveCount(AegisResourceSid::Test));

        $this->scope->memory->resources->close($active, 'test_cleanup');
        $this->scope->memory->resources->release('leaky-test-resource');
        $this->scope->expect->runtime()->clean();
    }

    public function testHandleExpectationCanTargetTypedResourceHandles(): void
    {
        $handle = $this->scope->memory->resources->open(AegisResourceSid::Test, id: 'typed-handle');
        $active = $this->scope->memory->resources->activate($handle);

        try {
            $this->scope->expect->handles()->closed(AegisResourceSid::Test);
            self::fail('Expected typed handle assertion to see the live resource.');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('aegis.test handle', $e->getMessage());
        } finally {
            $this->scope->memory->resources->close($active, 'test_cleanup');
            $this->scope->memory->resources->release('typed-handle');
        }

        $this->scope->expect->handles()->closed(AegisResourceSid::Test);
        $this->scope->expect->runtime()->clean();
    }

    public function testDiagnosticsExpectationReportsListenerFailures(): void
    {
        $memory = RuntimeMemory::forLedgerSize(16);

        try {
            $memory->events->listen(static function (): void {
                throw new RuntimeException('listener failed');
            });

            $memory->events->record('runtime.test');
            $expect = new PhalanxTestExpectations($memory);

            $expect->diagnostics()->listenerFailures(1);
            $expect->diagnostics()->droppedEvents(0);

            try {
                $expect->diagnostics()->healthy();
                self::fail('Expected diagnostics health assertion to reject listener failures.');
            } catch (AssertionFailedError $e) {
                self::assertStringContainsString('runtime listener failures', $e->getMessage());
            }
        } finally {
            $memory->shutdown();
        }
    }

    public function testDiagnosticsExpectationReportsDroppedEvents(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig(
            resourceRows: 8,
            edgeRows: 8,
            leaseRows: 8,
            annotationRows: 8,
            eventRows: 1,
            counterRows: 32,
            claimRows: 8,
            symbolRows: 32,
        ));

        try {
            $memory->events->record('runtime.test');
            $memory->events->record('runtime.test');
            $memory->events->record('runtime.test');

            (new PhalanxTestExpectations($memory))->diagnostics()->droppedEvents(2);
        } finally {
            $memory->shutdown();
        }
    }
}
