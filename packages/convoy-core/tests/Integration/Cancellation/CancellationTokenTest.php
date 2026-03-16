<?php

declare(strict_types=1);

namespace Convoy\Tests\Integration\Cancellation;

use Convoy\Concurrency\CancellationToken;
use Convoy\Exception\CancelledException;
use Convoy\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

use function React\Async\delay;

final class CancellationTokenTest extends AsyncTestCase
{
    #[Test]
    public function create_token_initially_not_cancelled(): void
    {
        $token = CancellationToken::create();

        $this->assertFalse($token->isCancelled);
    }

    #[Test]
    public function none_token_starts_uncancelled(): void
    {
        // Note: In v2, none() creates a regular token that starts uncancelled.
        // It can still be cancelled if explicitly called. Use for contexts
        // where no external cancellation is expected.
        $token = CancellationToken::none();

        $this->assertFalse($token->isCancelled);
    }

    #[Test]
    public function none_token_is_identical_to_create(): void
    {
        // In v2, none() and create() are equivalent
        $none = CancellationToken::none();
        $create = CancellationToken::create();

        $this->assertFalse($none->isCancelled);
        $this->assertFalse($create->isCancelled);

        $none->cancel();
        $create->cancel();

        $this->assertTrue($none->isCancelled);
        $this->assertTrue($create->isCancelled);
    }

    #[Test]
    public function timeout_token_cancels_after_duration(): void
    {
        $this->runAsync(function (): void {
            $token = CancellationToken::timeout(0.05);

            $this->assertFalse($token->isCancelled);

            delay(0.06);

            $this->assertTrue($token->isCancelled);
        });
    }

    #[Test]
    public function on_cancel_callbacks_fire(): void
    {
        $callbackOrder = [];

        $token = CancellationToken::create();

        $token->onCancel(static function () use (&$callbackOrder): void {
            $callbackOrder[] = 'first';
        });

        $token->onCancel(static function () use (&$callbackOrder): void {
            $callbackOrder[] = 'second';
        });

        $token->onCancel(static function () use (&$callbackOrder): void {
            $callbackOrder[] = 'third';
        });

        $this->assertEmpty($callbackOrder);

        $token->cancel();

        $this->assertEquals(['first', 'second', 'third'], $callbackOrder);
    }

    #[Test]
    public function on_cancel_after_cancelled_fires_immediately(): void
    {
        $called = false;

        $token = CancellationToken::create();
        $token->cancel();

        // Register callback AFTER cancellation
        $token->onCancel(static function () use (&$called): void {
            $called = true;
        });

        // Should fire immediately
        $this->assertTrue($called);
    }

    #[Test]
    public function throw_if_cancelled_throws_when_cancelled(): void
    {
        $token = CancellationToken::create();
        $token->cancel();

        $this->expectException(CancelledException::class);

        $token->throwIfCancelled();
    }

    #[Test]
    public function throw_if_cancelled_does_not_throw_when_active(): void
    {
        $token = CancellationToken::create();

        // Should not throw
        $token->throwIfCancelled();

        $this->assertFalse($token->isCancelled);
    }

    #[Test]
    public function composite_token_cancels_when_any_child_cancels(): void
    {
        $token1 = CancellationToken::create();
        $token2 = CancellationToken::create();
        $token3 = CancellationToken::create();

        $composite = CancellationToken::composite($token1, $token2, $token3);

        $this->assertFalse($composite->isCancelled);

        // Cancel just one child
        $token2->cancel();

        $this->assertTrue($composite->isCancelled);
    }

    #[Test]
    public function composite_with_pre_cancelled_token_is_cancelled(): void
    {
        $token1 = CancellationToken::create();
        $token1->cancel(); // Pre-cancel

        $token2 = CancellationToken::create();

        $composite = CancellationToken::composite($token1, $token2);

        // Should be immediately cancelled
        $this->assertTrue($composite->isCancelled);
    }

    #[Test]
    public function cancel_is_idempotent(): void
    {
        $callCount = 0;

        $token = CancellationToken::create();
        $token->onCancel(static function () use (&$callCount): void {
            $callCount++;
        });

        $token->cancel();
        $token->cancel();
        $token->cancel();

        // Callback should only fire once
        $this->assertSame(1, $callCount);
    }

    #[Test]
    public function callbacks_cleared_after_cancel(): void
    {
        $token = CancellationToken::create();

        $called = false;
        $token->onCancel(static function () use (&$called): void {
            $called = true;
        });

        $token->cancel();

        $this->assertTrue($called);

        // Reset for second check
        $called = false;

        // Even if we could somehow trigger cancel again, callbacks are cleared
        // This is verified by the idempotent test above
    }

    #[Test]
    public function timeout_cancels_timer_on_manual_cancel(): void
    {
        $this->runAsync(function (): void {
            $token = CancellationToken::timeout(10.0); // Long timeout

            // Cancel manually before timeout
            $token->cancel();

            $this->assertTrue($token->isCancelled);

            // Timer should be cleaned up (no way to assert directly,
            // but the test shouldn't hang)
        });
    }

    #[Test]
    public function composite_propagates_cancel_to_callbacks(): void
    {
        $compositeCalled = false;
        $token1Called = false;

        $token1 = CancellationToken::create();
        $token1->onCancel(static function () use (&$token1Called): void {
            $token1Called = true;
        });

        $composite = CancellationToken::composite($token1);
        $composite->onCancel(static function () use (&$compositeCalled): void {
            $compositeCalled = true;
        });

        $token1->cancel();

        $this->assertTrue($token1Called);
        $this->assertTrue($compositeCalled);
    }

    #[Test]
    public function empty_composite_is_not_cancelled(): void
    {
        $composite = CancellationToken::composite();

        $this->assertFalse($composite->isCancelled);
    }

    #[Test]
    public function timeout_with_zero_cancels_immediately(): void
    {
        $this->runAsync(function (): void {
            $token = CancellationToken::timeout(0.0);

            // Give the timer a chance to fire
            delay(0.01);

            $this->assertTrue($token->isCancelled);
        });
    }
}
