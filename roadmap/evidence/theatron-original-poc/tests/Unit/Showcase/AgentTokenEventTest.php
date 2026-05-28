<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Showcase;

use Phalanx\Theatron\Demos\Showcase\Event\AgentTokenEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentTokenEventTest extends TestCase
{
    #[Test]
    public function roundtrip_preserves_payload(): void
    {
        $event = new AgentTokenEvent(agentId: 'researcher', delta: 'The ');
        $restored = AgentTokenEvent::fromPayload($event->toPayload());

        self::assertSame('researcher', $restored->agentId);
        self::assertSame('The ', $restored->delta);
    }

    #[Test]
    public function from_payload_defaults_missing_keys(): void
    {
        $event = AgentTokenEvent::fromPayload([]);

        self::assertSame('', $event->agentId);
        self::assertSame('', $event->delta);
    }
}
