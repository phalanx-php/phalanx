<?php

declare(strict_types=1);

namespace Phalanx\Worker\Tests\Unit\Protocol;

use Phalanx\Worker\Protocol\Codec;
use Phalanx\Worker\Protocol\StreamEmit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamEmitTest extends TestCase
{
    #[Test]
    public function to_array_includes_type_and_fields(): void
    {
        $emit = new StreamEmit(
            taskId: 'reactor.agent-1.abc123',
            eventClass: 'App\\Event\\TokenReceived',
            payload: ['token' => 'hello', 'index' => 0],
        );

        $array = $emit->toArray();

        self::assertSame('stream_emit', $array['type']);
        self::assertSame('reactor.agent-1.abc123', $array['task_id']);
        self::assertSame('App\\Event\\TokenReceived', $array['event_class']);
        self::assertSame(['token' => 'hello', 'index' => 0], $array['payload']);
    }

    #[Test]
    public function from_array_reconstructs_emit(): void
    {
        $data = [
            'task_id' => 'reactor.agent-1.abc123',
            'event_class' => 'App\\Event\\TokenReceived',
            'payload' => ['delta' => 'world'],
        ];

        $emit = StreamEmit::fromArray($data);

        self::assertSame('reactor.agent-1.abc123', $emit->taskId);
        self::assertSame('App\\Event\\TokenReceived', $emit->eventClass);
        self::assertSame(['delta' => 'world'], $emit->payload);
    }

    #[Test]
    public function from_array_defaults_payload_to_empty(): void
    {
        $data = [
            'task_id' => 'reactor.x.1',
            'event_class' => 'App\\Event\\Heartbeat',
        ];

        $emit = StreamEmit::fromArray($data);

        self::assertSame([], $emit->payload);
    }

    #[Test]
    public function from_array_throws_on_missing_task_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('task_id');

        StreamEmit::fromArray(['event_class' => 'Foo']);
    }

    #[Test]
    public function from_array_throws_on_missing_event_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('event_class');

        StreamEmit::fromArray(['task_id' => 'x']);
    }

    #[Test]
    public function codec_encode_decode_roundtrip(): void
    {
        $original = new StreamEmit(
            taskId: 'reactor.research.6829f',
            eventClass: 'App\\Showcase\\Event\\AgentTokenEvent',
            payload: ['agentId' => 'researcher', 'delta' => 'The '],
        );

        $encoded = Codec::encode($original);

        self::assertStringEndsWith("\n", $encoded);
        self::assertStringContainsString('"type":"stream_emit"', $encoded);

        $decoded = Codec::decode($encoded);

        self::assertInstanceOf(StreamEmit::class, $decoded);
        self::assertSame($original->taskId, $decoded->taskId);
        self::assertSame($original->eventClass, $decoded->eventClass);
        self::assertSame($original->payload, $decoded->payload);
    }

    #[Test]
    public function codec_decode_handles_empty_payload(): void
    {
        $json = '{"type":"stream_emit","task_id":"t1","event_class":"Foo\\\\Bar","payload":[]}';

        $decoded = Codec::decode($json);

        self::assertInstanceOf(StreamEmit::class, $decoded);
        self::assertSame([], $decoded->payload);
    }
}
