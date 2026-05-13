<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Event;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Event\TokenUsage;
use PHPUnit\Framework\TestCase;

final class AgentEventResetTest extends TestCase
{
    public function testResetUpdatesAllProperties(): void
    {
        $event = new AgentEvent(AgentEventKind::LlmStart, null, 1.0, TokenUsage::zero(), 0);

        $usage = new TokenUsage(input: 10, output: 5);
        $event->reset(AgentEventKind::AgentComplete, 'Leonidas', 42.0, $usage, 3, 'strategist');

        self::assertSame(AgentEventKind::AgentComplete, $event->kind);
        self::assertSame('Leonidas', $event->data);
        self::assertSame(42.0, $event->elapsed);
        self::assertSame($usage, $event->usageSoFar);
        self::assertSame(3, $event->step);
        self::assertSame('strategist', $event->agent);
    }

    public function testResetClearsAgentWhenOmitted(): void
    {
        $event = new AgentEvent(AgentEventKind::LlmStart, null, 0.0, TokenUsage::zero(), 0, 'commander');

        $event->reset(AgentEventKind::TokenComplete, null, 1.0, TokenUsage::zero(), 1);

        self::assertNull($event->agent);
    }

    public function testSetAgentMutatesInPlace(): void
    {
        $usage = new TokenUsage(input: 5, output: 3);
        $event = new AgentEvent(AgentEventKind::TokenDelta, 'text', 1.0, $usage, 2);
        $originalId = spl_object_id($event);

        $event->setAgent('Pericles');

        self::assertSame($originalId, spl_object_id($event));
        self::assertSame('Pericles', $event->agent);
        self::assertSame(AgentEventKind::TokenDelta, $event->kind);
        self::assertSame('text', $event->data);
        self::assertSame(1.0, $event->elapsed);
        self::assertSame($usage, $event->usageSoFar);
        self::assertSame(2, $event->step);
    }

    public function testMultipleResetsStable(): void
    {
        $event = new AgentEvent(AgentEventKind::LlmStart, null, 0.0, TokenUsage::zero(), 0);

        $kinds = [AgentEventKind::TokenDelta, AgentEventKind::ToolCallStart, AgentEventKind::AgentComplete, AgentEventKind::Escalation];

        for ($i = 0; $i < 10; $i++) {
            $kind = $kinds[$i % count($kinds)];
            $usage = new TokenUsage(input: $i, output: $i * 2);
            $agent = $i % 3 === 0 ? "agent-$i" : null;
            $event->reset($kind, "cycle-$i", (float) $i, $usage, $i, $agent);

            self::assertSame($kind, $event->kind);
            self::assertSame("cycle-$i", $event->data);
            self::assertSame((float) $i, $event->elapsed);
            self::assertSame($usage, $event->usageSoFar);
            self::assertSame($i, $event->step);
            self::assertSame($agent, $event->agent);
        }
    }
}
