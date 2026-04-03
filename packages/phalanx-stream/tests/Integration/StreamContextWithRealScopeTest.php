<?php

declare(strict_types=1);

namespace Phalanx\Stream\Tests\Integration;

use Phalanx\Application;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\Exception\CancelledException;
use Phalanx\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Emitter;
use Phalanx\Stream\Tests\Support\AsyncTestCase;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Promise\resolve;

/**
 * Verifies that Emitter::produce() works correctly when the StreamContext is a
 * real ExecutionScope rather than the stub used in unit tests.
 *
 * The existing EmitterTest / EmitterStreamTest suites use a makeContext() stub
 * where await() is raw React\Async\await() — no cancellation racing. That stub
 * never exercises the real path: an ExecutionScope passed as StreamContext.
 *
 * These tests cover the pattern used by RawInputReader, StdinReader, and
 * ProjectWatcher, all of which call $ctx->await($promise) inside a producer
 * closure and rely on scope-managed cancellation to terminate correctly.
 */
final class StreamContextWithRealScopeTest extends AsyncTestCase
{
    #[Test]
    public function execution_scope_satisfies_stream_context_contract(): void
    {
        $app = Application::starting()->compile();
        $scope = $app->createScope();

        $this->assertInstanceOf(StreamContext::class, $scope);
    }

    #[Test]
    public function produce_ctx_await_resolves_with_real_scope(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $emitter = Emitter::produce(static function (Channel $ch, StreamContext $ctx): void {
                $value = $ctx->await(resolve('scope-resolved'));
                $ch->emit($value);
            });

            $scope = $app->createScope();
            $items = $scope->execute(Task::of(
                static fn(ExecutionScope $s) => iterator_to_array($emitter($s))
            ));

            $this->assertSame(['scope-resolved'], $items);
        });
    }

    #[Test]
    public function produce_ctx_await_interrupted_by_scope_cancellation(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $token = CancellationToken::create();
            $scope = $app->createScope(token: $token);

            $emitter = Emitter::produce(static function (Channel $ch, StreamContext $ctx): void {
                $deferred = new Deferred();
                $ctx->await($deferred->promise()); // suspends until cancellation races it
            });

            Loop::addTimer(0.01, static function () use ($token): void {
                $token->cancel();
            });

            $this->expectException(CancelledException::class);

            $scope->execute(Task::of(
                static fn(ExecutionScope $s) => iterator_to_array($emitter($s))
            ));
        });
    }

    #[Test]
    public function channel_consumer_interrupted_by_scope_cancellation(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $token = CancellationToken::create();
            $scope = $app->createScope(token: $token);

            // Producer emits one item then parks — channel stays open
            $emitter = Emitter::produce(static function (Channel $ch, StreamContext $ctx): void {
                $ch->emit('first');
                $deferred = new Deferred();
                $ctx->await($deferred->promise()); // producer stays alive
            });

            $collected = [];

            Loop::addTimer(0.02, static function () use ($token): void {
                $token->cancel();
            });

            $this->expectException(CancelledException::class);

            $scope->execute(Task::of(
                static function (ExecutionScope $s) use ($emitter, &$collected): void {
                    foreach ($emitter($s) as $item) {
                        $collected[] = $item;
                    }
                }
            ));
        });
    }

    #[Test]
    public function produce_multiple_items_then_completes_with_real_scope(): void
    {
        $app = Application::starting()->compile();

        $this->runAsync(function () use ($app): void {
            $emitter = Emitter::produce(static function (Channel $ch, StreamContext $ctx): void {
                foreach (['a', 'b', 'c'] as $val) {
                    $resolved = $ctx->await(resolve($val));
                    $ch->emit($resolved);
                }
            });

            $scope = $app->createScope();
            $items = $scope->execute(Task::of(
                static fn(ExecutionScope $s) => iterator_to_array($emitter($s))
            ));

            $this->assertSame(['a', 'b', 'c'], $items);
        });
    }
}
