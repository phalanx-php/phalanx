<?php

declare(strict_types=1);

namespace Phalanx\Styx\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Testing\PhalanxTestCase;

/**
 * Mechanism proof for bounded Channel backpressure (research Claim 5).
 * A fast producer should be throttled when a slow consumer is reading from
 * a bounded channel. This is the substrate that lets Styx extend backpressure
 * to external sources (including child processes via StreamingProcess).
 */
final class ChannelBackpressureTest extends PhalanxTestCase
{
    public function testBoundedChannelThrottlesFastProducer(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = new Channel(bufferSize: 1);
            $producerReachedBlockedEmit = false;
            $producerReleased = new Channel(bufferSize: 1);
            $producerReady = new Channel(bufferSize: 1);

            $scope->go(static function () use (
                $channel,
                $producerReady,
                $producerReleased,
                &$producerReachedBlockedEmit,
            ): void {
                $channel->emit('first');
                $producerReady->emit(true);
                $channel->emit('second');
                $producerReachedBlockedEmit = true;
                $producerReleased->emit(true);
                $channel->complete();
            });

            self::assertTrue(self::readOne($producerReady));
            $scope->delay(0.01);

            self::assertFalse($producerReachedBlockedEmit);

            $items = [];
            foreach ($channel->consume() as $_) {
                $items[] = $_;
                break;
            }

            self::assertSame(['first'], $items);
            self::assertTrue(self::readOne($producerReleased));
            self::assertTrue($producerReachedBlockedEmit);

            foreach ($channel->consume() as $_) {
                $items[] = $_;
            }

            self::assertSame(['first', 'second'], $items);
        });
    }

    private static function readOne(Channel $channel): mixed
    {
        foreach ($channel->consume() as $value) {
            return $value;
        }

        self::fail('Expected channel value.');
    }
}
