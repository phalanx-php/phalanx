<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing\Lenses;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\Lenses\LedgerLens;
use Phalanx\Testing\TestApp;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class LedgerLensTest extends TestCase
{
    public function testAegisNativeLensIsAlwaysAvailable(): void
    {
        $app = TestApp::boot();

        try {
            $lens = $app->ledger;

            self::assertInstanceOf(LedgerLens::class, $lens);
            self::assertSame($lens, $app->ledger, 'lens accessor returns the cached instance');
        } finally {
            $app->shutdown();
        }
    }

    public function testLiveCountsZeroOnFreshApp(): void
    {
        $app = TestApp::boot();

        try {
            self::assertSame(0, $app->ledger->liveTaskCount());
            self::assertSame(0, $app->ledger->liveScopeCount());
        } finally {
            $app->shutdown();
        }
    }

    public function testAssertNoOrphansPassesAfterCompletedRun(): void
    {
        $app = TestApp::boot();

        try {
            $app->application->scoped(Task::named(
                'demo.ledger.completed',
                static fn(ExecutionScope $scope): int => 1,
            ));

            $app->ledger->assertNoOrphans();
        } finally {
            $app->shutdown();
        }
    }

    public function testTreeRendersLiveTasksDuringExecution(): void
    {
        $app = TestApp::boot();

        try {
            $observed = '';

            $app->application->scoped(Task::named(
                'demo.ledger.snapshot.parent',
                static function (ExecutionScope $_scope) use ($app, &$observed): void {
                    $observed = $app->ledger->tree();
                },
            ));

            self::assertStringContainsString('demo.ledger.snapshot.parent', $observed);
            self::assertSame(0, $app->ledger->liveTaskCount());
        } finally {
            $app->shutdown();
        }
    }

    public function testAssertNoLiveScopesPassesOnIdleApp(): void
    {
        $app = TestApp::boot();

        try {
            $app->ledger->assertNoLiveScopes();
        } finally {
            $app->shutdown();
        }
    }

    public function testAssertTreeContainsFailsWhenNeedleAbsent(): void
    {
        $app = TestApp::boot();

        try {
            $this->expectException(AssertionFailedError::class);

            $app->ledger->assertTreeContains('this-substring-is-not-in-the-tree');
        } finally {
            $app->shutdown();
        }
    }
}
