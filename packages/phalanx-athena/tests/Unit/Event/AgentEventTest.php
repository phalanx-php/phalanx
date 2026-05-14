<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Event;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Event\StructuredData;
use Phalanx\Athena\Event\TokenDelta;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Event\ToolCallData;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentEventTest extends TestCase
{
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

    public function testNamedConstructorsCreateDistinctOwnedEvents(): void
    {
        $usage = new TokenUsage(input: 10, output: 5);

        $first = AgentEvent::tokenComplete(1.0, $usage, 0);
        $second = AgentEvent::tokenComplete(2.0, $usage, 1);

        self::assertNotSame(spl_object_id($first), spl_object_id($second));
        self::assertSame(AgentEventKind::TokenComplete, $first->kind);
        self::assertSame(1.0, $first->elapsed);
        self::assertSame(0, $first->step);
    }

    public function testNamedConstructorsMapKindsAndPayloads(): void
    {
        $usage = new TokenUsage(input: 10, output: 5);
        $delta = new TokenDelta(text: 'Apollo');
        $tool = new ToolCallData(callId: 'call-1', toolName: 'lookup');
        $structured = new StructuredData(field: 'answer', value: 42);
        $error = new RuntimeException('failed');

        $events = [
            AgentEvent::llmStart(step: 1, elapsed: 1.0),
            AgentEvent::tokenDelta($delta, elapsed: 2.0, usage: $usage, step: 1),
            AgentEvent::tokenComplete(elapsed: 3.0, usage: $usage, step: 1),
            AgentEvent::toolCallStart($tool, elapsed: 4.0, usage: $usage, step: 1),
            AgentEvent::toolCallComplete($tool, elapsed: 5.0, usage: $usage, step: 1),
            AgentEvent::stepComplete(step: 1, elapsed: 6.0, usage: $usage),
            AgentEvent::structuredOutput($structured, elapsed: 7.0, usage: $usage, step: 1),
            AgentEvent::complete('done', elapsed: 8.0, usage: $usage, step: 1),
            AgentEvent::error($error, elapsed: 9.0, usage: $usage, step: 1),
            AgentEvent::escalation('manual', elapsed: 10.0, usage: $usage, step: 1),
        ];

        self::assertSame([
            AgentEventKind::LlmStart,
            AgentEventKind::TokenDelta,
            AgentEventKind::TokenComplete,
            AgentEventKind::ToolCallStart,
            AgentEventKind::ToolCallComplete,
            AgentEventKind::StepComplete,
            AgentEventKind::StructuredOutput,
            AgentEventKind::AgentComplete,
            AgentEventKind::AgentError,
            AgentEventKind::Escalation,
        ], array_map(static fn(AgentEvent $event): AgentEventKind => $event->kind, $events));

        self::assertSame($delta, $events[1]->data);
        self::assertSame($tool, $events[3]->data);
        self::assertSame($tool, $events[4]->data);
        self::assertSame($structured, $events[6]->data);
        self::assertSame('done', $events[7]->data);
        self::assertSame($error, $events[8]->data);
        self::assertSame('manual', $events[9]->data);
    }
}
