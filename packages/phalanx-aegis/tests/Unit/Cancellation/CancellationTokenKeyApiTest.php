<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Cancellation;

use Phalanx\Cancellation\CancellationToken;
use PHPUnit\Framework\TestCase;

final class CancellationTokenKeyApiTest extends TestCase
{
    public function testOnCancelReturnsNonNegativeKey(): void
    {
        $token = CancellationToken::create();

        $key = $token->onCancel(static function (): void {});

        self::assertGreaterThanOrEqual(0, $key);
    }

    public function testOffCancelRemovesListener(): void
    {
        $token = CancellationToken::create();

        $fired = false;
        $key = $token->onCancel(static function () use (&$fired): void {
            $fired = true;
        });

        $token->offCancel($key);
        $token->cancel();

        self::assertFalse($fired);
    }

    public function testOnCancelOnAlreadyCancelledReturnsSentinel(): void
    {
        $token = CancellationToken::create();
        $token->cancel();

        $fired = false;
        $key = $token->onCancel(static function () use (&$fired): void {
            $fired = true;
        });

        self::assertSame(-1, $key);
        self::assertTrue($fired);
    }

    public function testOffCancelWithSentinelIsNoop(): void
    {
        $token = CancellationToken::create();
        $token->offCancel(-1);

        self::assertFalse($token->isCancelled);
    }

    public function testCompositeUsesKeyBasedUnregistration(): void
    {
        $parent = CancellationToken::create();
        $composite = CancellationToken::composite($parent);

        $fired = false;
        $composite->onCancel(static function () use (&$fired): void {
            $fired = true;
        });

        $composite->release();
        $parent->cancel();

        self::assertFalse($fired);
    }

    public function testMultipleKeysAreUnique(): void
    {
        $token = CancellationToken::create();

        $keyA = $token->onCancel(static function (): void {});
        $keyB = $token->onCancel(static function (): void {});

        self::assertNotSame($keyA, $keyB);
    }

    public function testOffCancelOnlyRemovesTargetedListener(): void
    {
        $token = CancellationToken::create();

        $firedA = false;
        $firedB = false;
        $keyA = $token->onCancel(static function () use (&$firedA): void {
            $firedA = true;
        });
        $token->onCancel(static function () use (&$firedB): void {
            $firedB = true;
        });

        $token->offCancel($keyA);
        $token->cancel();

        self::assertFalse($firedA);
        self::assertTrue($firedB);
    }
}
