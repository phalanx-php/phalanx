<?php

declare(strict_types=1);

namespace Phalanx\Styx\Tests\Integration;

use Phalanx\Styx\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Styx\Emitter;
use Phalanx\Styx\Tests\Support\AsyncTestCase;
use Phalanx\Testing\Stub\TestStreamContext;
use PHPUnit\Framework\Attributes\Test;
use React\EventLoop\Loop;
use React\Stream\ThroughStream;

final class EmitterStreamTest extends AsyncTestCase
{
    #[Test]
    public function full_pipeline_stream_to_operators_to_terminal(): void
    {
        $this->runAsync(function (): void {
            $through = new ThroughStream();

            Loop::futureTick(static function () use ($through): void {
                $through->write(1);
                $through->write(2);
                $through->write(3);
                $through->write(4);
                $through->end();
            });

            $result = Emitter::stream($through)
                ->filter(static fn(int $v): bool => $v > 1)
                ->map(static fn(int $v): int => $v * 10)
                ->toArray();

            $ctx = new TestStreamContext();
            $items = $result($ctx);

            $this->assertSame([20, 30, 40], $items);
        });
    }

    #[Test]
    public function produce_to_reduce_terminal(): void
    {
        $sum = Emitter::produce(static function (Channel $ch): void {
            $ch->emit(1);
            $ch->emit(2);
            $ch->emit(3);
        })->reduce(static fn(int $acc, int $v): int => $acc + $v, 0);

        $ctx = new TestStreamContext();
        $result = $sum($ctx);

        $this->assertSame(6, $result);
    }

    #[Test]
    public function chained_operators_preserve_hooks(): void
    {
        $log = [];

        $emitter = Emitter::produce(static function (Channel $ch): void {
            $ch->emit(1);
            $ch->emit(2);
        })
            ->onStart(static function () use (&$log): void { $log[] = 'start'; })
            ->map(static fn(int $v): int => $v * 2)
            ->filter(static fn(int $v): bool => $v > 2);

        $ctx = new TestStreamContext();
        $items = iterator_to_array($emitter($ctx));

        $this->assertSame([4], $items);
        $this->assertContains('start', $log);
    }

    #[Test]
    public function merge_multiple_stream_sources(): void
    {
        $s1 = Emitter::produce(static function (Channel $ch): void {
            $ch->emit('s1-a');
            $ch->emit('s1-b');
        });

        $s2 = Emitter::produce(static function (Channel $ch): void {
            $ch->emit('s2-a');
        });

        $s3 = Emitter::produce(static function (Channel $ch): void {
            $ch->emit('s3-a');
            $ch->emit('s3-b');
            $ch->emit('s3-c');
        });

        $merged = $s1->merge($s2, $s3);

        $ctx = new TestStreamContext();
        $items = iterator_to_array($merged($ctx));

        sort($items);
        $this->assertSame(['s1-a', 's1-b', 's2-a', 's3-a', 's3-b', 's3-c'], $items);
    }

    #[Test]
    public function emitter_implements_stream_source(): void
    {
        $emitter = Emitter::produce(static function (Channel $ch): void {
            $ch->emit('test');
        });

        $this->assertInstanceOf(\Phalanx\Stream\Contract\StreamSource::class, $emitter);
    }

    #[Test]
    public function first_terminal_returns_first_item(): void
    {
        $first = Emitter::produce(static function (Channel $ch): void {
            $ch->emit('alpha');
            $ch->emit('beta');
        })->first();

        $ctx = new TestStreamContext();
        $this->assertSame('alpha', $first($ctx));
    }

    #[Test]
    public function consume_returns_null(): void
    {
        $processed = [];

        $drain = Emitter::produce(static function (Channel $ch): void {
            $ch->emit('x');
            $ch->emit('y');
        })
            ->onEach(static function ($v) use (&$processed): void { $processed[] = $v; })
            ->consume();

        $ctx = new TestStreamContext();
        $result = $drain($ctx);

        $this->assertNull($result);
        $this->assertSame(['x', 'y'], $processed);
    }

    #[Test]
    public function error_in_producer_propagates(): void
    {
        $emitter = Emitter::produce(static function (Channel $ch): void {
            $ch->emit('ok');
            throw new \RuntimeException('producer failed');
        });

        $ctx = new TestStreamContext();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('producer failed');

        iterator_to_array($emitter($ctx));
    }

    #[Test]
    public function empty_stream_completes(): void
    {
        $emitter = Emitter::produce(static function (): void {
        });

        $ctx = new TestStreamContext();
        $items = iterator_to_array($emitter($ctx));

        $this->assertSame([], $items);
    }
}
