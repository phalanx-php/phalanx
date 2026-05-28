<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Stream;

use Phalanx\Theatron\Stream\SerializableStreamEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SerializableStreamEventTest extends TestCase
{
    #[Test]
    public function roundtrip_preserves_payload(): void
    {
        $original = SampleSerializableEvent::fromPayload([
            'agentId' => 'researcher',
            'delta' => 'The answer is',
        ]);

        $payload = $original->toPayload();
        $restored = SampleSerializableEvent::fromPayload($payload);

        self::assertSame('researcher', $restored->agentId);
        self::assertSame('The answer is', $restored->delta);
    }

    #[Test]
    public function to_payload_returns_expected_keys(): void
    {
        $event = new SampleSerializableEvent('analyst', 'hello world');

        $payload = $event->toPayload();

        self::assertArrayHasKey('agentId', $payload);
        self::assertArrayHasKey('delta', $payload);
        self::assertSame('analyst', $payload['agentId']);
    }
}

final class SampleSerializableEvent implements SerializableStreamEvent
{
    public function __construct(
        private(set) string $agentId,
        private(set) string $delta,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): static
    {
        return new static( // @phpstan-ignore new.static
            agentId: (string) ($payload['agentId'] ?? ''),
            delta: (string) ($payload['delta'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'agentId' => $this->agentId,
            'delta' => $this->delta,
        ];
    }
}
