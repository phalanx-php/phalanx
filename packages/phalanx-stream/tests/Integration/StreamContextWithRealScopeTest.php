<?php

declare(strict_types=1);

namespace Phalanx\Stream\Tests\Integration;

use Phalanx\Concurrency\CancellationToken;
use Phalanx\Exception\CancelledException;
use Phalanx\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Emitter;
use Phalanx\Task\Task;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Promise\resolve;

final class StreamContextWithRealScopeTest extends TestCase
{
    #[Test]
    public function execution_scope_satisfies_stream_context_contract(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            Assert::assertInstanceOf(StreamContext::class, $scope);
        });
    }

    #[Test]
    public function produce_ctx_await_resolves_with_real_scope(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            $emitter = Emitter::produce(static function (Channel $ch, StreamContext $ctx): void {
                $value = $ctx->await(resolve('scope-resolved'));
                $ch->emit($value);
            });

            $items = $scope->execute(Task::of(
                static fn(ExecutionScope $s) => iterator_to_array($emitter($s)),
            ));

            Assert::assertSame(['scope-resolved'], $items);
        });
    }

    #[Test]
    public function produce_ctx_await_interrupted_by_scope_cancellation(): void
    {
        $token = CancellationToken::create();
        $threw = false;

        Loop::addTimer(0.01, static function () use ($token): void {
            $token->cancel();
        });

        try {
            TestScope::run(
                static function (ExecutionScope $scope): void {
                    $emitter = Emitter::produce(static function (Channel $ch, StreamContext $ctx): void {
                        $deferred = new Deferred();
                        $ctx->await($deferred->promise());
                    });

                    $scope->execute(Task::of(
                        static fn(ExecutionScope $s) => iterator_to_array($emitter($s)),
                    ));
                },
                token: $token,
            );
        } catch (CancelledException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected CancelledException');
    }

    #[Test]
    public function channel_consumer_interrupted_by_scope_cancellation(): void
    {
        $token = CancellationToken::create();
        $threw = false;

        Loop::addTimer(0.02, static function () use ($token): void {
            $token->cancel();
        });

        try {
            TestScope::run(
                static function (ExecutionScope $scope): void {
                    $emitter = Emitter::produce(static function (Channel $ch, StreamContext $ctx): void {
                        $ch->emit('first');
                        $deferred = new Deferred();
                        $ctx->await($deferred->promise());
                    });

                    $scope->execute(Task::of(
                        static function (ExecutionScope $s) use ($emitter): void {
                            foreach ($emitter($s) as $item) {
                                // consume
                            }
                        },
                    ));
                },
                token: $token,
            );
        } catch (CancelledException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected CancelledException');
    }

    #[Test]
    public function produce_multiple_items_then_completes_with_real_scope(): void
    {
        TestScope::run(static function (ExecutionScope $scope): void {
            $emitter = Emitter::produce(static function (Channel $ch, StreamContext $ctx): void {
                foreach (['a', 'b', 'c'] as $val) {
                    $resolved = $ctx->await(resolve($val));
                    $ch->emit($resolved);
                }
            });

            $items = $scope->execute(Task::of(
                static fn(ExecutionScope $s) => iterator_to_array($emitter($s)),
            ));

            Assert::assertSame(['a', 'b', 'c'], $items);
        });
    }
}
