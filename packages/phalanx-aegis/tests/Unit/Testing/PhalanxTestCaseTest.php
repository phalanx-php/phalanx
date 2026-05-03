<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing;

use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\ScopeIdentity;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
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
}
