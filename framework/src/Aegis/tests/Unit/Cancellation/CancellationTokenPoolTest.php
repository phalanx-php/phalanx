<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Cancellation;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Cancellation\CancellationToken;
use PHPUnit\Framework\TestCase;

final class CancellationTokenPoolTest extends TestCase
{
    public function testResetForPoolClearsAllState(): void
    {
        $token = CancellationToken::create();
        $token->onCancel(static function (): void {});
        $token->cancel();

        self::assertTrue($token->isCancelled);

        $token->resetForPool();

        self::assertFalse($token->isCancelled);
        self::assertFalse($token->immutableNone);
    }

    public function testResetTokenAcceptsNewCompositeWiring(): void
    {
        $parent = CancellationToken::create();
        $token = CancellationToken::create();

        $token->resetForPool();
        $token->wireComposite($parent);

        self::assertFalse($token->isCancelled);

        $parent->cancel();

        self::assertTrue($token->isCancelled);
    }

    public function testWireCompositePreCancelsFromAlreadyCancelledParent(): void
    {
        $parent = CancellationToken::create();
        $parent->cancel();

        $token = CancellationToken::create();
        $token->resetForPool();
        $token->wireComposite($parent);

        self::assertTrue($token->isCancelled);
    }

    public function testNoneTokenExcludedFromPooling(): void
    {
        $none = CancellationToken::none();

        self::assertTrue($none->immutableNone);
    }

    public function testPooledTokenPropagatesCancellationToListeners(): void
    {
        $parent = CancellationToken::create();
        $token = CancellationToken::create();

        $token->resetForPool();
        $token->wireComposite($parent);

        $fired = false;
        $token->onCancel(static function () use (&$fired): void {
            $fired = true;
        });

        $parent->cancel();

        self::assertTrue($fired);
    }

    public function testMultipleResetCyclesStable(): void
    {
        $token = CancellationToken::create();

        for ($i = 0; $i < 5; $i++) {
            $parent = CancellationToken::create();
            $token->resetForPool();
            $token->wireComposite($parent);

            self::assertFalse($token->isCancelled);

            $parent->cancel();
            self::assertTrue($token->isCancelled);
        }
    }

    public function testResetClearsListenersFromPreviousCycle(): void
    {
        $token = CancellationToken::create();

        $firstCycleFired = false;
        $token->onCancel(static function () use (&$firstCycleFired): void {
            $firstCycleFired = true;
        });

        $token->resetForPool();

        $parent = CancellationToken::create();
        $token->wireComposite($parent);
        $parent->cancel();

        self::assertFalse($firstCycleFired, 'Listeners from previous cycle must not fire');
    }

    public function testThrowIfCancelledWorksAfterReset(): void
    {
        $token = CancellationToken::create();
        $token->cancel();

        $token->resetForPool();
        self::assertFalse($token->isCancelled);
        $token->throwIfCancelled();

        $parent = CancellationToken::create();
        $token->wireComposite($parent);
        $parent->cancel();

        $this->expectException(Cancelled::class);
        $token->throwIfCancelled();
    }
}
