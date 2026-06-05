<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Runtime\Runtime;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\AiProviders\Runtime\Runtime\Runtime;
use Phalanx\AiProviders\Runtime\CancellationException;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\TaskScope;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Runtime-backed {@see Runtime} adapter.
 *
 * All tests use a hand-written {@see TaskScope} stub so the Runtime package
 * itself (and its Swoole dependency) need not be booted. The adapter is
 * thin delegation; tests verify mapping, not Runtime internals.
 */
final class RuntimeTest extends TestCase
{
    #[Test]
    public function delegatesCallToScope(): void
    {
        $adapter = new Runtime(self::stub());
        $result = $adapter->call(static fn (): string => 'agora');

        self::assertSame('agora', $result);
    }

    #[Test]
    public function callForwardsWaitReasonToScope(): void
    {
        $stub = self::stub();
        $adapter = new Runtime($stub);

        // A non-null label must be translated to a typed WaitReason and forwarded.
        // Use a Greek-flavored label to make the assertion specific and traceable.
        $adapter->call(static fn (): string => 'agora', 'sparta.muster');

        self::assertInstanceOf(WaitReason::class, $stub->lastWaitReason);
        // Verify the ai-providers label string is preserved in the Runtime detail field.
        self::assertSame('sparta.muster', $stub->lastWaitReason->detail);
        // Verify WaitReason::custom() sets WaitKind::Custom on the kind axis.
        self::assertSame(\Phalanx\Supervisor\WaitKind::Custom, $stub->lastWaitReason->kind);

        // A null label must forward null — no WaitReason is created.
        $adapter->call(static fn (): string => 'polis');

        self::assertNull($stub->lastWaitReason);
    }

    #[Test]
    public function callPassesReturnValueThrough(): void
    {
        $adapter = new Runtime(self::stub());
        $result = $adapter->call(static fn (): int => 300);

        self::assertSame(300, $result);
    }

    #[Test]
    public function isCancelledReturnsFalseByDefault(): void
    {
        $token = CancellationToken::create();
        $adapter = new Runtime(self::stub($token));

        self::assertFalse($adapter->isCancelled());
    }

    #[Test]
    public function isCancelledReturnsTrueAfterTokenCancellation(): void
    {
        $token = CancellationToken::create();
        $adapter = new Runtime(self::stub($token));

        $token->cancel();

        self::assertTrue($adapter->isCancelled());
    }

    #[Test]
    public function throwIfCancelledDoesNotThrowWhenLive(): void
    {
        $adapter = new Runtime(self::stub());

        $adapter->throwIfCancelled();
        self::addToAssertionCount(1); // No exception thrown — test passes by reaching this line.
    }

    #[Test]
    public function throwIfCancelledRewrapsAsCancellationException(): void
    {
        $token = CancellationToken::create();
        $token->cancel();
        $adapter = new Runtime(self::stub($token));

        $this->expectException(CancellationException::class);

        $adapter->throwIfCancelled();
    }

    #[Test]
    public function cancellationExceptionChainsRuntimeCancelled(): void
    {
        $token = CancellationToken::create();
        $token->cancel();
        $adapter = new Runtime(self::stub($token));

        try {
            $adapter->throwIfCancelled();
            self::fail('Expected CancellationException');
        } catch (CancellationException $e) {
            // The previous exception is the Runtime Cancelled thrown by the token.
            self::assertNotNull($e->getPrevious());
        }
    }

    #[Test]
    public function onCancelDoesNotRunCleanupOnNormalDispose(): void
    {
        // Without cancellation the cleanup must NOT run — onCancel is
        // cancellation-only, not a generic teardown hook.
        $called = false;
        $stub = self::stub();

        $adapter = new Runtime($stub);
        $adapter->onCancel(static function () use (&$called): void {
            $called = true;
        });

        $stub->dispose();

        self::assertFalse($called);
    }

    #[Test]
    public function onCancelRunsCleanupWhenScopeIsCancelled(): void
    {
        $called = false;
        $token = CancellationToken::create();
        $stub = self::stub($token);

        $adapter = new Runtime($stub);
        $adapter->onCancel(static function () use (&$called): void {
            $called = true;
        });

        $token->cancel();
        $stub->dispose();

        self::assertTrue($called);
    }

    #[Test]
    public function onCancelWithAlreadyDisposedAndCancelledScopeRunsImmediately(): void
    {
        $called = false;
        $token = CancellationToken::create();
        $token->cancel();
        $stub = self::stub($token, disposed: true);

        $adapter = new Runtime($stub);
        $adapter->onCancel(static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue($called);
    }

    /**
     * Constructs a minimal {@see TaskScope} stub backed by a real
     * {@see CancellationToken}. The stub's `call()` delegates to the work
     * closure directly; `onDispose()` accumulates callbacks and `dispose()`
     * runs them LIFO.
     */
    /**
     * @return TaskScope&object{lastWaitReason: ?WaitReason}
     */
    private static function stub(?CancellationToken $token = null, bool $disposed = false): TaskScope
    {
        $token ??= CancellationToken::create();

        return new class ($token, $disposed) implements TaskScope {
            public bool $isCancelled { get => $this->token->isCancelled; }

            public RuntimeContext $runtime {
                get => throw new \RuntimeException('not implemented in stub');
            }

            public ?WaitReason $lastWaitReason = null;

            /** @var list<\Closure(): void> */
            private array $disposeStack = [];

            public function __construct(private(set) CancellationToken $token, private bool $disposed)
            {
            }

            public function call(\Closure $fn, ?WaitReason $waitReason = null): mixed
            {
                $this->lastWaitReason = $waitReason;

                return $fn();
            }

            public function throwIfCancelled(): void
            {
                $this->token->throwIfCancelled();
            }

            public function cancellation(): CancellationToken
            {
                return $this->token;
            }

            public function onDispose(\Closure $callback): void
            {
                if ($this->disposed) {
                    $callback();
                    return;
                }
                $this->disposeStack[] = $callback;
            }

            public function dispose(): void
            {
                $this->disposed = true;
                $callbacks = array_reverse($this->disposeStack);
                $this->disposeStack = [];
                foreach ($callbacks as $cb) {
                    $cb();
                }
            }

            public function service(string $type): object
            {
                throw new \RuntimeException('not implemented in stub');
            }

            public function trace(): Trace
            {
                throw new \RuntimeException('not implemented in stub');
            }

            public function execute(Scopeable|Executable|\Closure $task): mixed
            {
                throw new \RuntimeException('not implemented in stub');
            }

            public function executeFresh(Scopeable|Executable|\Closure $task): mixed
            {
                throw new \RuntimeException('not implemented in stub');
            }
        };
    }
}
