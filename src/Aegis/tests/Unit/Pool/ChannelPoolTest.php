<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Pool;

use Phalanx\Pool\ChannelPool;
use Phalanx\Runtime\Swoole\SwooleChannel;
use Phalanx\Runtime\Swoole\SwooleRuntime;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

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
        SwooleRuntime::run(static function (): void {
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
