<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tests\Integration;

use Phalanx\Ai\Stream\SseParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    #[Test]
    public function parses_simple_data_event(): void
    {
        $parser = new SseParser();
        $events = iterator_to_array($parser->feed("data: hello world\n\n"));

        $this->assertCount(1, $events);
        $this->assertNull($events[0]['event']);
        $this->assertSame('hello world', $events[0]['data']);
    }

    #[Test]
    public function parses_named_event(): void
    {
        $parser = new SseParser();
        $events = iterator_to_array($parser->feed("event: message\ndata: hello\n\n"));

        $this->assertCount(1, $events);
        $this->assertSame('message', $events[0]['event']);
        $this->assertSame('hello', $events[0]['data']);
    }

    #[Test]
    public function parses_multi_line_data(): void
    {
        $parser = new SseParser();
        $events = iterator_to_array($parser->feed("data: line1\ndata: line2\n\n"));

        $this->assertCount(1, $events);
        $this->assertSame("line1\nline2", $events[0]['data']);
    }

    #[Test]
    public function handles_chunked_input(): void
    {
        $parser = new SseParser();

        $events1 = iterator_to_array($parser->feed("data: hel"));
        $this->assertCount(0, $events1);

        $events2 = iterator_to_array($parser->feed("lo\n\n"));
        $this->assertCount(1, $events2);
        $this->assertSame('hello', $events2[0]['data']);
    }

    #[Test]
    public function parses_multiple_events_in_one_chunk(): void
    {
        $parser = new SseParser();
        $events = iterator_to_array($parser->feed("data: first\n\ndata: second\n\n"));

        $this->assertCount(2, $events);
        $this->assertSame('first', $events[0]['data']);
        $this->assertSame('second', $events[1]['data']);
    }

    #[Test]
    public function ignores_comment_lines(): void
    {
        $parser = new SseParser();
        $events = iterator_to_array($parser->feed(": this is a comment\ndata: actual data\n\n"));

        $this->assertCount(1, $events);
        $this->assertSame('actual data', $events[0]['data']);
    }

    #[Test]
    public function ignores_empty_blocks(): void
    {
        $parser = new SseParser();
        $events = iterator_to_array($parser->feed(": comment only\n\n"));

        $this->assertCount(0, $events);
    }

    #[Test]
    public function parses_anthropic_style_sse(): void
    {
        $parser = new SseParser();
        $chunk = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_01\",\"type\":\"message\",\"role\":\"assistant\",\"model\":\"claude-sonnet-4-20250514\",\"usage\":{\"input_tokens\":25,\"output_tokens\":1}}}\n\n";

        $events = iterator_to_array($parser->feed($chunk));

        $this->assertCount(1, $events);
        $this->assertSame('message_start', $events[0]['event']);

        $data = json_decode($events[0]['data'], true);
        $this->assertSame('message_start', $data['type']);
        $this->assertSame(25, $data['message']['usage']['input_tokens']);
    }

    #[Test]
    public function parses_openai_style_sse(): void
    {
        $parser = new SseParser();
        $chunk = "data: {\"id\":\"chatcmpl-abc\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"},\"finish_reason\":null}]}\n\n";

        $events = iterator_to_array($parser->feed($chunk));

        $this->assertCount(1, $events);
        $data = json_decode($events[0]['data'], true);
        $this->assertSame('Hello', $data['choices'][0]['delta']['content']);
    }

    #[Test]
    public function handles_done_marker(): void
    {
        $parser = new SseParser();
        $events = iterator_to_array($parser->feed("data: [DONE]\n\n"));

        $this->assertCount(1, $events);
        $this->assertSame('[DONE]', $events[0]['data']);
    }

    #[Test]
    public function reset_clears_buffer(): void
    {
        $parser = new SseParser();

        iterator_to_array($parser->feed("data: partial"));
        $parser->reset();

        $events = iterator_to_array($parser->feed("data: fresh\n\n"));
        $this->assertCount(1, $events);
        $this->assertSame('fresh', $events[0]['data']);
    }
}
