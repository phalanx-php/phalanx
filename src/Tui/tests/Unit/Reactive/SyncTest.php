<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Reactive;

use Phalanx\Tui\Reactive\Sync;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SyncTest extends TestCase
{
    #[Test]
    public function setupRunsImmediatelyOnConstruction(): void
    {
        $ran = false;

        new Sync(
            scope: null,
            setup: static function () use (&$ran): void {
                $ran = true;
            },
        );

        self::assertTrue($ran);
    }

    #[Test]
    public function updateWithSameKeyIsNoop(): void
    {
        $runs = 0;

        $sync = new Sync(
            scope: null,
            setup: static function () use (&$runs): void {
                $runs++;
            },
            currentKey: 'initial',
        );

        self::assertSame(1, $runs);

        $sync->update('initial');
        self::assertSame(1, $runs);
    }

    #[Test]
    public function updateRunsCleanupThenSetup(): void
    {
        $log = [];

        $sync = new Sync(
            scope: null,
            setup: static function () use (&$log): \Closure {
                $log[] = 'setup';
                return static function () use (&$log): void {
                    $log[] = 'cleanup';
                };
            },
            currentKey: 'a',
        );

        self::assertSame(['setup'], $log);

        $sync->update('b');
        self::assertSame(['setup', 'cleanup', 'setup'], $log);
    }

    #[Test]
    public function disposeRunsCleanupOnce(): void
    {
        $cleanups = 0;

        $sync = new Sync(
            scope: null,
            setup: static function () use (&$cleanups): \Closure {
                return static function () use (&$cleanups): void {
                    $cleanups++;
                };
            },
        );

        $sync->dispose();
        self::assertSame(1, $cleanups);
    }

    #[Test]
    public function doubleDisposeIsSafe(): void
    {
        $cleanups = 0;

        $sync = new Sync(
            scope: null,
            setup: static function () use (&$cleanups): \Closure {
                return static function () use (&$cleanups): void {
                    $cleanups++;
                };
            },
        );

        $sync->dispose();
        $sync->dispose();
        self::assertSame(1, $cleanups);
    }

    #[Test]
    public function updateAfterDisposeIsNoop(): void
    {
        $runs = 0;

        $sync = new Sync(
            scope: null,
            setup: static function () use (&$runs): void {
                $runs++;
            },
            currentKey: 'a',
        );

        self::assertSame(1, $runs);

        $sync->dispose();
        $sync->update('b');
        self::assertSame(1, $runs);
    }

    #[Test]
    public function nonStaticSetupThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sync setup must be a static closure.');

        new Sync(
            scope: null,
            setup: fn(): null => null);
    }
}
