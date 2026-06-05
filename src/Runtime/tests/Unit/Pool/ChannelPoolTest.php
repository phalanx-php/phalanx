<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Pool;

use Phalanx\Pool\ChannelPool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel as SwooleChannel;

use function Swoole\Coroutine\run as swoole_coroutine_run;

final class ChannelPoolTest extends TestCase
{
    public static function make(mixed $config): \stdClass
    {
        return new \stdClass();
    }

    #[Test]
    public function getReturnsConnectionFromChannel(): void
    {
        $client = new \stdClass();
        $channel = $this->createStub(SwooleChannel::class);
        $channel->method('isEmpty')->willReturn(false);
        $channel->method('pop')->willReturn($client);

        $pool = new ChannelPool(
            factoryClass: self::class,
            config: null,
            size: 4,
            channel: $channel,
        );

        $this->assertSame($client, $pool->get());
        $this->assertSame(1, $pool->active);
    }

    #[Test]
    public function getReturnsFalseOnPopTimeout(): void
    {
        $channel = $this->createStub(SwooleChannel::class);
        $channel->method('isEmpty')->willReturn(false);
        $channel->method('pop')->willReturn(false);

        $pool = new ChannelPool(
            factoryClass: self::class,
            config: null,
            size: 4,
            channel: $channel,
        );

        $this->assertFalse($pool->get(0.01));
        $this->assertSame(0, $pool->active);
    }

    #[Test]
    public function putDecrementsActive(): void
    {
        $client = new \stdClass();
        $channel = $this->createStub(SwooleChannel::class);
        $channel->method('isEmpty')->willReturn(false);
        $channel->method('pop')->willReturn($client);
        $channel->method('push')->willReturn(true);

        $pool = new ChannelPool(
            factoryClass: self::class,
            config: null,
            size: 4,
            channel: $channel,
        );

        $pool->get();
        $this->assertSame(1, $pool->active);

        $pool->put($client);
        $this->assertSame(0, $pool->active);
    }

    #[Test]
    public function putDoesNotDecrementBelowZero(): void
    {
        $channel = $this->createStub(SwooleChannel::class);
        $channel->method('push')->willReturn(true);

        $pool = new ChannelPool(
            factoryClass: self::class,
            config: null,
            size: 4,
            channel: $channel,
        );

        $pool->put(new \stdClass());
        $this->assertSame(0, $pool->active);
    }

    #[Test]
    public function putOnClosedPoolIsNoop(): void
    {
        $channel = $this->createStub(SwooleChannel::class);
        $channel->method('isEmpty')->willReturn(true);

        $pool = new ChannelPool(
            factoryClass: self::class,
            config: null,
            size: 4,
            channel: $channel,
        );

        $pool->close();
        $pool->put(new \stdClass());
        $this->assertSame(0, $pool->active);
    }

    #[Test]
    public function closeResetsCounters(): void
    {
        $client = new \stdClass();
        $channel = $this->createStub(SwooleChannel::class);
        $channel->method('isEmpty')->willReturnOnConsecutiveCalls(false, true);
        $channel->method('pop')->willReturn($client);

        $pool = new ChannelPool(
            factoryClass: self::class,
            config: null,
            size: 4,
            channel: $channel,
        );

        $pool->close();

        $this->assertSame(0, $pool->active);
    }

    #[Test]
    public function doubleCloseIsIdempotent(): void
    {
        $channel = $this->createStub(SwooleChannel::class);
        $channel->method('isEmpty')->willReturn(true);

        $pool = new ChannelPool(
            factoryClass: self::class,
            config: null,
            size: 4,
            channel: $channel,
        );

        $pool->close();
        $pool->close();

        $this->assertSame(0, $pool->active);
    }

    #[Test]
    public function getReturnsFalseAfterClose(): void
    {
        $channel = $this->createStub(SwooleChannel::class);
        $channel->method('isEmpty')->willReturn(true);

        $pool = new ChannelPool(
            factoryClass: self::class,
            config: null,
            size: 4,
            channel: $channel,
        );

        $pool->close();

        $this->assertFalse($pool->get(0.01));
        $this->assertSame(0, $pool->active);
    }

    #[Test]
    public function constructionWithoutChannelCreatesLazily(): void
    {
        swoole_coroutine_run(static function (): void {
            $pool = new ChannelPool(
                factoryClass: self::class,
                config: null,
                size: 4,
            );

            self::assertInstanceOf(\stdClass::class, $pool->get());
            self::assertSame(1, $pool->active);
        });
    }
}
