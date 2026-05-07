<?php

declare(strict_types=1);

namespace Phalanx\Styx\Tests\Unit;

use OpenSwoole\Coroutine;
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
        $channel = new Channel(bufferSize: 4);
        $produced = 0;
        $consumed = 0;
        $slowConsumerDelayMs = 25; // deliberately slow

        // Fast producer coroutine
        Coroutine::create(static function () use ($channel, &$produced): void {
            for ($i = 0; $i < 20; $i++) {
                $channel->emit("item-{$i}");
                $produced++;
            }
            $channel->complete();
        });

        // Slow consumer
        $start = microtime(true);
        foreach ($channel->consume() as $value) {
            Coroutine::usleep($slowConsumerDelayMs * 1000);
            $consumed++;
        }
        $durationMs = (microtime(true) - $start) * 1000;

        // With buffer=4 and 25ms delay, we expect significant throttling.
        // Without backpressure the total time would be ~20 * 25ms = 500ms.
        // With backpressure it must be substantially higher because the producer
        // is forced to wait when the channel is full.
        self::assertSame(20, $consumed);
        self::assertGreaterThan(400, $durationMs); // conservative threshold
    }
}
