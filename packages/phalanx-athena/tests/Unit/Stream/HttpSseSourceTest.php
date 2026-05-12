<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Stream;

use Phalanx\Athena\Stream\HttpSseSource;
use Phalanx\Iris\HttpStream;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Suspendable;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Test double feeding canned HTTP response bytes. HttpStream is
 * non-final so tests can extend it without reflection; production
 * has exactly one implementation; the open hierarchy serves tests.
 */
final class FakeHttpStream extends HttpStream
{
    public bool $eof {
        get => $this->chunks === [];
    }

    /** @param list<string> $chunks */
    public function __construct(
        private array $chunks,
    ) {
    }

    #[\Override]
    public function read(Suspendable $scope, int $bytes = 8192): string
    {
        if ($this->chunks === []) {
            return '';
        }
        return array_shift($this->chunks);
    }
}

/**
 * HttpSseSource is the bridge between the OpenSwoole-native HttpStream
 * (byte-level coroutine reads) and the existing Athena SseParser
 * (event-level structured output). Coverage drives chunked input
 * patterns, including events split across read boundaries, so the
 * provider integration is robust against real-world TCP framing.
 */
#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class HttpSseSourceTest extends PhalanxTestCase
{
    public function testYieldsEventsFromSingleChunk(): void
    {
        $source = new HttpSseSource(new FakeHttpStream([
            "event: message_start\ndata: {\"type\":\"start\"}\n\n",
            "event: message_stop\ndata: {\"type\":\"stop\"}\n\n",
        ]));

        $events = $this->collect($source);

        self::assertCount(2, $events);
        self::assertSame('message_start', $events[0]['event']);
        self::assertSame('{"type":"start"}', $events[0]['data']);
        self::assertSame('message_stop', $events[1]['event']);
    }

    public function testHandlesEventSplitAcrossChunks(): void
    {
        $source = new HttpSseSource(new FakeHttpStream([
            "event: message_start\nda",
            "ta: {\"hello\":\"wor",
            "ld\"}\n\n",
        ]));

        $events = $this->collect($source);

        self::assertCount(1, $events);
        self::assertSame('message_start', $events[0]['event']);
        self::assertSame('{"hello":"world"}', $events[0]['data']);
    }

    public function testIgnoresCommentLinesAndEmptyData(): void
    {
        $source = new HttpSseSource(new FakeHttpStream([
            ": keep-alive\nevent: ping\n\n",
            "event: data\ndata: payload\n\n",
        ]));

        $events = $this->collect($source);

        self::assertCount(1, $events);
        self::assertSame('data', $events[0]['event']);
        self::assertSame('payload', $events[0]['data']);
    }

    public function testStopsOnEmptyChunk(): void
    {
        $source = new HttpSseSource(new FakeHttpStream([]));

        $events = $this->collect($source);

        self::assertSame([], $events);
    }

    /**
     * @return list<array{event: ?string, data: string}>
     */
    private function collect(HttpSseSource $source): array
    {
        return $this->scope->run(static function (ExecutionScope $scope) use ($source): array {
            $events = [];
            foreach ($source->events($scope) as $event) {
                $events[] = $event;
            }
            return $events;
        });
    }
}
