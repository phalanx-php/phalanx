<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Showcase;

use Phalanx\Theatron\Demos\Showcase\Event\AgentStatusEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentStatusEventTest extends TestCase
{
    #[Test]
    public function roundtrip_preserves_payload(): void
    {
        $event = new AgentStatusEvent(agentId: 'steward', status: 'complete', totalTokens: 150);
        $restored = AgentStatusEvent::fromPayload($event->toPayload());

        self::assertSame('steward', $restored->agentId);
        self::assertSame('complete', $restored->status);
        self::assertSame(150, $restored->totalTokens);
    }

    #[Test]
    public function from_payload_defaults_missing_keys(): void
    {
        $event = AgentStatusEvent::fromPayload([]);

        self::assertSame('', $event->agentId);
        self::assertSame('', $event->status);
        self::assertSame(0, $event->totalTokens);
    }

    #[Test]
    public function total_tokens_defaults_to_zero(): void
    {
        $event = new AgentStatusEvent(agentId: 'a', status: 'thinking');

        self::assertSame(0, $event->totalTokens);
    }
}
