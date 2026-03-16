<?php

declare(strict_types=1);

namespace Convoy\Tests\Http\Integration;

use Convoy\Http\Sse\SseChannel;
use Convoy\Http\Sse\SseEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Stream\ThroughStream;

final class SseResponseTest extends TestCase
{
    #[Test]
    public function encoder_produces_valid_sse_format(): void
    {
        $output = SseEncoder::encode('hello world');

        $this->assertSame("data: hello world\n\n", $output);
    }

    #[Test]
    public function encoder_with_event_and_id(): void
    {
        $output = SseEncoder::encode('payload', event: 'update', id: '42');

        $this->assertStringContainsString("id: 42\n", $output);
        $this->assertStringContainsString("event: update\n", $output);
        $this->assertStringContainsString("data: payload\n", $output);
        $this->assertStringEndsWith("\n\n", $output);
    }

    #[Test]
    public function encoder_with_retry(): void
    {
        $output = SseEncoder::encode('data', retry: 5000);

        $this->assertStringContainsString("retry: 5000\n", $output);
    }

    #[Test]
    public function encoder_handles_multiline_data(): void
    {
        $output = SseEncoder::encode("line one\nline two\nline three");

        $this->assertStringContainsString("data: line one\n", $output);
        $this->assertStringContainsString("data: line two\n", $output);
        $this->assertStringContainsString("data: line three\n", $output);
    }

    #[Test]
    public function channel_broadcasts_to_all_clients(): void
    {
        $channel = new SseChannel(bufferSize: 10);

        $output1 = '';
        $output2 = '';

        $stream1 = new ThroughStream();
        $stream1->on('data', static function (string $data) use (&$output1): void {
            $output1 .= $data;
        });

        $stream2 = new ThroughStream();
        $stream2->on('data', static function (string $data) use (&$output2): void {
            $output2 .= $data;
        });

        $channel->connect($stream1);
        $channel->connect($stream2);

        $this->assertSame(2, $channel->clientCount());

        $channel->send('test message', event: 'ping');

        $this->assertStringContainsString('data: test message', $output1);
        $this->assertStringContainsString('data: test message', $output2);
        $this->assertStringContainsString('event: ping', $output1);
    }

    #[Test]
    public function channel_replays_missed_events(): void
    {
        $channel = new SseChannel(bufferSize: 10);

        $channel->send('first');
        $channel->send('second');
        $channel->send('third');

        $output = '';
        $stream = new ThroughStream();
        $stream->on('data', static function (string $data) use (&$output): void {
            $output .= $data;
        });

        $channel->connect($stream, lastEventId: '1');

        $this->assertStringContainsString('data: second', $output);
        $this->assertStringContainsString('data: third', $output);
        $this->assertStringNotContainsString('data: first', $output);
    }

    #[Test]
    public function channel_client_disconnect_reduces_count(): void
    {
        $channel = new SseChannel();

        $stream = new ThroughStream();
        $channel->connect($stream);

        $this->assertSame(1, $channel->clientCount());

        $stream->close();

        $this->assertSame(0, $channel->clientCount());
    }

    #[Test]
    public function channel_respects_buffer_size(): void
    {
        $channel = new SseChannel(bufferSize: 3);

        $channel->send('a');  // id 1 -- will be evicted
        $channel->send('b');  // id 2 -- kept
        $channel->send('c');  // id 3 -- kept
        $channel->send('d');  // id 4 -- kept, evicts 'a'

        // Connect with lastEventId '2' (still in buffer) -- replays c, d
        $output = '';
        $stream = new ThroughStream();
        $stream->on('data', static function (string $data) use (&$output): void {
            $output .= $data;
        });

        $channel->connect($stream, lastEventId: '2');

        $this->assertStringNotContainsString('data: b', $output);
        $this->assertStringContainsString('data: c', $output);
        $this->assertStringContainsString('data: d', $output);
    }

    #[Test]
    public function channel_evicted_last_event_id_replays_nothing(): void
    {
        $channel = new SseChannel(bufferSize: 2);

        $channel->send('a');  // id 1
        $channel->send('b');  // id 2
        $channel->send('c');  // id 3 -- evicts 'a'

        // id '1' was evicted -- nothing to replay
        $output = '';
        $stream = new ThroughStream();
        $stream->on('data', static function (string $data) use (&$output): void {
            $output .= $data;
        });

        $channel->connect($stream, lastEventId: '1');

        $this->assertEmpty($output);
    }

    #[Test]
    public function channel_default_event_type(): void
    {
        $channel = new SseChannel(defaultEvent: 'message');

        $output = '';
        $stream = new ThroughStream();
        $stream->on('data', static function (string $data) use (&$output): void {
            $output .= $data;
        });

        $channel->connect($stream);
        $channel->send('hello');

        $this->assertStringContainsString('event: message', $output);
    }
}
