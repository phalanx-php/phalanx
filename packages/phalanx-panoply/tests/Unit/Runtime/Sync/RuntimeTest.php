<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Runtime\Sync;

use Phalanx\Panoply\Runtime\CancellationException;
use Phalanx\Panoply\Runtime\Sync\Runtime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuntimeTest extends TestCase
{
    #[Test]
    public function callReturnsWorkResult(): void
    {
        $runtime = self::fixture();

        $result = $runtime->call(static fn (): string => 'sparta');

        self::assertSame('sparta', $result);
    }

    #[Test]
    public function callPassesNullResultThrough(): void
    {
        $runtime = self::fixture();

        $result = $runtime->call(static fn (): mixed => null);

        self::assertNull($result);
    }

    #[Test]
    public function isCancelledStartsFalse(): void
    {
        self::assertFalse(self::fixture()->isCancelled());
    }

    #[Test]
    public function isCancelledReturnsTrueAfterCancel(): void
    {
        $runtime = self::fixture();
        $runtime->cancel();

        self::assertTrue($runtime->isCancelled());
    }

    #[Test]
    public function throwIfCancelledDoesNothingWhenNotCancelled(): void
    {
        $runtime = self::fixture();

        // No exception thrown.
        $runtime->throwIfCancelled();
        self::assertTrue(true);
    }

    #[Test]
    public function throwIfCancelledThrowsAfterCancel(): void
    {
        $runtime = self::fixture();
        $runtime->cancel();

        $this->expectException(CancellationException::class);

        $runtime->throwIfCancelled();
    }

    #[Test]
    public function callThrowsIfAlreadyCancelled(): void
    {
        $runtime = self::fixture();
        $runtime->cancel();

        $this->expectException(CancellationException::class);

        $runtime->call(static fn (): string => 'never reached');
    }

    #[Test]
    public function onCancelRunsCleanupOnCancel(): void
    {
        $runtime = self::fixture();
        $called = false;

        $runtime->onCancel(static function () use (&$called): void {
            $called = true;
        });

        $runtime->cancel();

        self::assertTrue($called);
    }

    #[Test]
    public function cleanupsRunInLifoOrder(): void
    {
        $runtime = self::fixture();
        $order = [];

        $runtime->onCancel(static function () use (&$order): void {
            $order[] = 'first';
        });
        $runtime->onCancel(static function () use (&$order): void {
            $order[] = 'second';
        });
        $runtime->onCancel(static function () use (&$order): void {
            $order[] = 'third';
        });

        $runtime->cancel();

        self::assertSame(['third', 'second', 'first'], $order);
    }

    #[Test]
    public function cancelIsDeduplicated(): void
    {
        $runtime = self::fixture();
        $callCount = 0;

        $runtime->onCancel(static function () use (&$callCount): void {
            $callCount++;
        });

        $runtime->cancel();
        $runtime->cancel();

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function cleanupDoesNotRunWithoutCancel(): void
    {
        $runtime = self::fixture();
        $called = false;

        $runtime->onCancel(static function () use (&$called): void {
            $called = true;
        });

        self::assertFalse($called);
    }

    #[Test]
    public function onCancelAfterCancelRunsCleanupImmediately(): void
    {
        $runtime = self::fixture();
        $runtime->cancel();

        $ran = false;
        $runtime->onCancel(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran, 'cleanup registered after cancel() must run immediately');
    }

    private static function fixture(): Runtime
    {
        return new Runtime();
    }
}
