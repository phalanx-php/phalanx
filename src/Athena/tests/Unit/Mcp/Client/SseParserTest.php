<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Mcp\Client;

use Generator;
use Phalanx\Athena\Mcp\Client\SseParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    private SseParser $parser;

    #[Test]
    public function parsesSimpleDataEvent(): void
    {
        $lines = $this->lines(['data: hello', '']);

        $events = iterator_to_array($this->parser->parse($lines), false);

        self::assertCount(1, $events);
        self::assertSame('message', $events[0]['event']);
        self::assertSame('hello', $events[0]['data']);
        self::assertNull($events[0]['id']);
    }

    #[Test]
    public function parsesNamedEvent(): void
    {
        $lines = $this->lines(['event: endpoint', 'data: https://example.com/post', '']);

        $events = iterator_to_array($this->parser->parse($lines), false);

        self::assertCount(1, $events);
        self::assertSame('endpoint', $events[0]['event']);
        self::assertSame('https://example.com/post', $events[0]['data']);
    }

    #[Test]
    public function parsesEventWithId(): void
    {
        $lines = $this->lines(['id: 42', 'data: payload', '']);

        $events = iterator_to_array($this->parser->parse($lines), false);

        self::assertCount(1, $events);
        self::assertSame('42', $events[0]['id']);
        self::assertSame('payload', $events[0]['data']);
    }

    #[Test]
    public function joinsMultipleDataLines(): void
    {
        $lines = $this->lines(['data: line one', 'data: line two', 'data: line three', '']);

        $events = iterator_to_array($this->parser->parse($lines), false);

        self::assertCount(1, $events);
        self::assertSame("line one\nline two\nline three", $events[0]['data']);
    }

    #[Test]
    public function skipsCommentLines(): void
    {
        $lines = $this->lines([': this is a comment', 'data: real', '']);

        $events = iterator_to_array($this->parser->parse($lines), false);

        self::assertCount(1, $events);
        self::assertSame('real', $events[0]['data']);
    }

    #[Test]
    public function parsesMultipleEvents(): void
    {
        $lines = $this->lines([
            'event: endpoint',
            'data: /post',
            '',
            'event: message',
            'data: {"jsonrpc":"2.0"}',
            '',
        ]);

        $events = iterator_to_array($this->parser->parse($lines), false);

        self::assertCount(2, $events);
        self::assertSame('endpoint', $events[0]['event']);
        self::assertSame('message', $events[1]['event']);
    }

    #[Test]
    public function discardIncompleteEventAtStreamEnd(): void
    {
        $lines = $this->lines(['data: orphan']);

        $events = iterator_to_array($this->parser->parse($lines), false);

        self::assertCount(0, $events);
    }

    #[Test]
    public function resetsEventTypeAfterEachBoundary(): void
    {
        $lines = $this->lines([
            'event: custom',
            'data: first',
            '',
            'data: second',
            '',
        ]);

        $events = iterator_to_array($this->parser->parse($lines), false);

        self::assertCount(2, $events);
        self::assertSame('custom', $events[0]['event']);
        self::assertSame('message', $events[1]['event']);
    }

    #[Test]
    public function skipsLinesWithNoColon(): void
    {
        $lines = $this->lines(['garbage line', 'data: good', '']);

        $events = iterator_to_array($this->parser->parse($lines), false);

        self::assertCount(1, $events);
        self::assertSame('good', $events[0]['data']);
    }

    protected function setUp(): void
    {
        $this->parser = new SseParser();
    }

    /** @param list<string> $rawLines */
    private function lines(array $rawLines): Generator
    {
        yield from $rawLines;
    }
}
