<?php

declare(strict_types=1);

namespace Convoy\Stream\Tests\Integration;

use Convoy\Application;
use Convoy\Stream\Emitter;
use Convoy\Stream\ScopedStream;
use Convoy\Stream\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;
use React\EventLoop\Loop;
use React\Stream\ThroughStream;

final class LazySequenceFromStreamTest extends AsyncTestCase
{
    #[Test]
    public function emitter_bridges_readable_stream_to_generator(): void
    {
        $this->runAsync(function (): void {
            $app = Application::starting()->compile();
            $scope = $app->createScope();

            $through = new ThroughStream();

            Loop::futureTick(static function () use ($through): void {
                $through->write('chunk1');
                $through->write('chunk2');
                $through->write('chunk3');
                $through->end();
            });

            $stream = ScopedStream::from($scope, Emitter::stream($through));
            $result = $stream->toArray();

            $this->assertSame(['chunk1', 'chunk2', 'chunk3'], $result);
        });
    }

    #[Test]
    public function emitter_with_callable_source(): void
    {
        $this->runAsync(function (): void {
            $app = Application::starting()->compile();
            $scope = $app->createScope();

            $emitter = Emitter::stream(static function (): ThroughStream {
                $stream = new ThroughStream();

                Loop::futureTick(static function () use ($stream): void {
                    $stream->write('lazy1');
                    $stream->write('lazy2');
                    $stream->end();
                });

                return $stream;
            });

            $stream = ScopedStream::from($scope, $emitter);
            $result = $stream->toArray();

            $this->assertSame(['lazy1', 'lazy2'], $result);
        });
    }
}
