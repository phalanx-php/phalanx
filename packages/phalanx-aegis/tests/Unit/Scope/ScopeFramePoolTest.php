<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Application;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScopeFramePoolTest extends TestCase
{
    #[Test]
    public function disposed_scope_frames_are_reused_without_reusing_public_scope_objects(): void
    {
        $app = Application::starting()->compile()->startup();

        try {
            $first = $app->createScope();
            self::assertInstanceOf(ExecutionLifecycleScope::class, $first);
            $firstId = $first->scopeId;
            self::assertSame(1, $app->supervisor()->poolStats()['scopeFrame']['borrowed']);

            $first->dispose();
            $afterFirstDispose = $app->supervisor()->poolStats()['scopeFrame'];
            self::assertSame(0, $afterFirstDispose['borrowed']);
            self::assertSame(1, $afterFirstDispose['free']);
            $hitsBeforeReuse = $afterFirstDispose['hits'];

            $second = $app->createScope();
            self::assertInstanceOf(ExecutionLifecycleScope::class, $second);

            try {
                $afterSecondCreate = $app->supervisor()->poolStats()['scopeFrame'];
                self::assertGreaterThan($hitsBeforeReuse, $afterSecondCreate['hits']);
                self::assertSame(1, $afterSecondCreate['borrowed']);
                self::assertNotSame($first, $second);
                self::assertNotSame($firstId, $second->scopeId);

                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessage('Execution scope has been disposed.');

                $first->service(PooledScopeState::class);
            } finally {
                $second->dispose();
            }
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function scope_frame_releases_after_scoped_exception(): void
    {
        $app = Application::starting()->compile()->startup();

        try {
            try {
                $app->scoped(Task::named(
                    'scope-frame.failure',
                    static function (ExecutionScope $_scope): never {
                        throw new RuntimeException('expected');
                    },
                ));
            } catch (RuntimeException $e) {
                self::assertSame('expected', $e->getMessage());
            }

            self::assertSame(0, $app->supervisor()->poolStats()['scopeFrame']['borrowed']);
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function child_scope_frames_release_after_concurrent_work(): void
    {
        $app = Application::starting()->compile();
        $borrowedDuringRun = null;

        try {
            $app->run(Task::named(
                'scope-frame.concurrent',
                static function (ExecutionScope $scope) use (&$borrowedDuringRun): void {
                    $scope->concurrent(
                        Task::of(static fn(): string => 'a'),
                        Task::of(static fn(): string => 'b'),
                    );

                    $borrowedDuringRun = $scope instanceof ExecutionLifecycleScope
                        ? $scope->supervisor()->poolStats()['scopeFrame']['borrowed']
                        : null;
                },
            ));

            self::assertSame(1, $borrowedDuringRun);
            self::assertSame(0, $app->supervisor()->poolStats()['scopeFrame']['borrowed']);
        } finally {
            $app->shutdown();
        }
    }
}

final class PooledScopeState
{
}
