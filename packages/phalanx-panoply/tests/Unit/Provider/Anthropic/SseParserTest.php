<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\Anthropic;

use Phalanx\Panoply\Provider\Anthropic\SseParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    #[Test]
    public function feedYieldsCompleteEventFromSingleChunk(): void
    {
        $parser = self::fixture();
        $chunk  = "event: message_start\ndata: {\"type\":\"message_start\"}\n\n";

        $events = iterator_to_array($parser->feed($chunk), preserve_keys: false);

        self::assertCount(1, $events);
        self::assertSame('message_start', $events[0]->type);
    }

    #[Test]
    public function feedYieldsNothingForPartialEvent(): void
    {
        $parser = self::fixture();
        $chunk  = "event: message_start\ndata: {\"type\":\"message_start\"}";

        $events = iterator_to_array($parser->feed($chunk), preserve_keys: false);

        self::assertCount(0, $events);
    }

    #[Test]
    public function feedYieldsEventSplitAcrossTwoChunks(): void
    {
        $parser  = self::fixture();
        $chunk1  = "event: message_start\ndata: {\"type\":\"mess";
        $chunk2  = "age_start\"}\n\n";

        $events1 = iterator_to_array($parser->feed($chunk1), preserve_keys: false);
        $events2 = iterator_to_array($parser->feed($chunk2), preserve_keys: false);

        self::assertCount(0, $events1);
        self::assertCount(1, $events2);
        self::assertSame('message_start', $events2[0]->type);
    }

    #[Test]
    public function feedYieldsMultipleEventsFromOneChunk(): void
    {
        $parser = self::fixture();
        $chunk  = "event: message_start\ndata: {\"type\":\"message_start\"}\n\n"
            . "event: content_block_start\ndata: {\"type\":\"content_block_start\"}\n\n";

        $events = iterator_to_array($parser->feed($chunk), preserve_keys: false);

        self::assertCount(2, $events);
        self::assertSame('message_start', $events[0]->type);
        self::assertSame('content_block_start', $events[1]->type);
    }

    #[Test]
    public function flushYieldsTrailingEventWithoutDoubleNewline(): void
    {
        $parser = self::fixture();
        $chunk  = "event: message_stop\ndata: {\"type\":\"message_stop\"}";

        iterator_to_array($parser->feed($chunk), preserve_keys: false);
        $events = iterator_to_array($parser->flush(), preserve_keys: false);

        self::assertCount(1, $events);
        self::assertSame('message_stop', $events[0]->type);
    }

    #[Test]
    public function flushClearsBufferAfterYielding(): void
    {
        $parser = self::fixture();
        $chunk  = "event: message_stop\ndata: {\"type\":\"message_stop\"}";

        iterator_to_array($parser->feed($chunk), preserve_keys: false);
        iterator_to_array($parser->flush(), preserve_keys: false);

        // Second flush yields nothing — buffer is empty.
        $events = iterator_to_array($parser->flush(), preserve_keys: false);
        self::assertCount(0, $events);
    }

    #[Test]
    public function sseCommentLinesAreIgnored(): void
    {
        $parser = self::fixture();
        $chunk  = ": keep-alive\nevent: message_start\ndata: {\"type\":\"message_start\"}\n\n";

        $events = iterator_to_array($parser->feed($chunk), preserve_keys: false);

        self::assertCount(1, $events);
        self::assertSame('message_start', $events[0]->type);
    }

    #[Test]
    public function malformedJsonDataEventIsDropped(): void
    {
        $parser = self::fixture();
        $chunk  = "event: message_start\ndata: not-valid-json\n\n"
            . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";

        $events = iterator_to_array($parser->feed($chunk), preserve_keys: false);

        // Malformed first event is dropped; valid second event survives.
        self::assertCount(1, $events);
        self::assertSame('message_stop', $events[0]->type);
    }

    #[Test]
    public function dataFieldIsParsedAsArray(): void
    {
        $parser = self::fixture();
        $json   = json_encode(['type' => 'message_start', 'message' => ['model' => 'claude-opus-4-7']]);
        $chunk  = "event: message_start\ndata: {$json}\n\n";

        $events = iterator_to_array($parser->feed($chunk), preserve_keys: false);

        self::assertCount(1, $events);
        self::assertSame('message_start', $events[0]->data['type'] ?? null);
        self::assertSame('claude-opus-4-7', $events[0]->data['message']['model'] ?? null);
    }

    #[Test]
    public function feedHandlesCrlfDelimiter(): void
    {
        $parser = self::fixture();
        // \r\n\r\n is a valid SSE event delimiter per the WHATWG spec.
        $chunk  = "event: message_start\r\ndata: {\"type\":\"message_start\"}\r\n\r\n";

        $events = iterator_to_array($parser->feed($chunk), preserve_keys: false);

        self::assertCount(1, $events);
        self::assertSame('message_start', $events[0]->type);
    }

    #[Test]
    public function feedHandlesCrOnlyDelimiter(): void
    {
        $parser = self::fixture();
        // \r\r is a valid SSE event delimiter per the WHATWG spec.
        $chunk  = "event: message_start\rdata: {\"type\":\"message_start\"}\r\r";

        $events = iterator_to_array($parser->feed($chunk), preserve_keys: false);

        self::assertCount(1, $events);
        self::assertSame('message_start', $events[0]->type);
    }

    #[Test]
    public function feedEmptyChunkYieldsNoEventsAndDoesNotCorruptBuffer(): void
    {
        $parser = self::fixture();
        // Prime the buffer with a partial event.
        iterator_to_array($parser->feed("event: message_start\ndata: {\"type\":\"mess"), preserve_keys: false);

        // Feed an empty chunk — buffer must be unchanged.
        $events = iterator_to_array($parser->feed(''), preserve_keys: false);
        self::assertCount(0, $events);

        // Complete the event to prove buffer is intact.
        $events = iterator_to_array($parser->feed("age_start\"}\n\n"), preserve_keys: false);
        self::assertCount(1, $events);
        self::assertSame('message_start', $events[0]->type);
    }

    #[Test]
    public function multiLineDataFieldsJoinedWithNewline(): void
    {
        // The SSE spec joins multiple data: lines with \n before parsing. Use
        // a JSON value that is intentionally split across two data: lines at a
        // safe boundary (between two top-level keys) so the joined string is
        // valid JSON.
        $parser = self::fixture();
        $chunk  = "event: message_start\ndata: {\"type\":\"message_start\",\ndata: \"extra\":true}\n\n";

        // This split produces `{"type":"message_start",\n"extra":true}` which is
        // valid JSON — the parser must join and decode it successfully.
        $events = iterator_to_array($parser->feed($chunk), preserve_keys: false);

        self::assertCount(1, $events);
        self::assertSame('message_start', $events[0]->type);
    }

    #[Test]
    public function optionalSpaceFieldPrefixParsedCorrectly(): void
    {
        $parser = self::fixture();
        // No space between colon and value — `event:foo` must parse as type=foo.
        $chunk  = "event:message_start\ndata:{\"type\":\"message_start\"}\n\n";

        $events = iterator_to_array($parser->feed($chunk), preserve_keys: false);

        self::assertCount(1, $events);
        self::assertSame('message_start', $events[0]->type);
    }

    private static function fixture(): SseParser
    {
        return new SseParser();
    }
}
