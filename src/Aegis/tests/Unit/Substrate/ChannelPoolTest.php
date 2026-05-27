<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Substrate;

use Phalanx\Substrate\ChannelHandle;
use Phalanx\Substrate\ChannelPool;
use PHPUnit\Framework\TestCase;

final class ChannelPoolTest extends TestCase
{
    public function testGetReturnsConnectionFromChannel(): void
    {
        $client = new \stdClass();
        $channel = $this->createStub(ChannelHandle::class);
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

    public function testGetReturnsFalseOnPopTimeout(): void
    {
        $channel = $this->createStub(ChannelHandle::class);
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

    public function testGetReturnsFalseWhenClosed(): void
    {
        $channel = $this->createStub(ChannelHandle::class);
        $channel->method('isEmpty')->willReturn(true);

        $pool = new ChannelPool(
            factoryClass: self::class,
            config: null,
            size: 4,
            channel: $channel,
        );

        $pool->close();

        $this->assertFalse($pool->get());
    }

    public function testPutDecrementsActive(): void
    {
        $client = new \stdClass();
        $channel = $this->createStub(ChannelHandle::class);
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

    public function testPutDoesNotDecrementBelowZero(): void
    {
        $channel = $this->createStub(ChannelHandle::class);
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

    public function testPutOnClosedPoolIsNoop(): void
    {
        $channel = $this->createStub(ChannelHandle::class);
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

    public function testCloseResetsCounters(): void
    {
        $client = new \stdClass();
        $channel = $this->createStub(ChannelHandle::class);
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

    public function testDoubleCloseIsIdempotent(): void
    {
        $channel = $this->createStub(ChannelHandle::class);
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

    public function testConstructionWithoutChannelDefersCreation(): void
    {
        $pool = new ChannelPool(
            factoryClass: self::class,
            config: null,
            size: 4,
        );

        $this->assertFalse($pool->get());
    }
}
