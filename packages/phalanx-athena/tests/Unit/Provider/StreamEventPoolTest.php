<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Provider;

use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Event\TokenDelta;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Event\ToolCallData;
use Phalanx\Athena\Provider\StreamEventPool;
use PHPUnit\Framework\TestCase;

final class StreamEventPoolTest extends TestCase
{
    private static function ringSize(): int
    {
        return (new \ReflectionClassConstant(StreamEventPool::class, 'RING_SIZE'))->getValue();
    }

    public function testEventRingGrowsThenResets(): void
    {
        $pool = new StreamEventPool();
        $ringSize = self::ringSize();
        $usage = new TokenUsage(input: 7, output: 3);

        $firstIds = [];
        for ($i = 0; $i < $ringSize; $i++) {
            $event = $pool->event(AgentEventKind::TokenDelta, "grow-$i", (float) $i, $usage, $i);
            $firstIds[] = spl_object_id($event);
        }

        $newUsage = new TokenUsage(input: 42, output: 18);
        $recycled = $pool->event(AgentEventKind::TokenComplete, 'recycled', 99.0, $newUsage, 77, 'Themistocles');

        self::assertSame($firstIds[0], spl_object_id($recycled));
        self::assertSame(AgentEventKind::TokenComplete, $recycled->kind);
        self::assertSame('recycled', $recycled->data);
        self::assertSame(99.0, $recycled->elapsed);
        self::assertSame($newUsage, $recycled->usageSoFar);
        self::assertSame(77, $recycled->step);
        self::assertSame('Themistocles', $recycled->agent);
    }

    public function testDeltaRingGrowsThenResets(): void
    {
        $pool = new StreamEventPool();
        $ringSize = self::ringSize();

        $firstIds = [];
        for ($i = 0; $i < $ringSize; $i++) {
            $delta = $pool->delta(text: "chunk-$i");
            $firstIds[] = spl_object_id($delta);
        }

        $recycled = $pool->delta(text: 'recycled');

        self::assertSame($firstIds[0], spl_object_id($recycled));
        self::assertSame('recycled', $recycled->text);
    }

    public function testTokenDeltaPoolsBothEventAndDelta(): void
    {
        $pool = new StreamEventPool();
        $usage = TokenUsage::zero();

        $event = $pool->tokenDelta('Apollo', 1.0, $usage, 0);

        self::assertSame(AgentEventKind::TokenDelta, $event->kind);
        self::assertInstanceOf(TokenDelta::class, $event->data);
        self::assertSame('Apollo', $event->data->text);
    }

    public function testTokenDeltaRecyclesBothRingsAfterWrap(): void
    {
        $pool = new StreamEventPool();
        $ringSize = self::ringSize();
        $usage = TokenUsage::zero();

        $first = $pool->tokenDelta('first', 0.0, $usage, 0);
        $firstEventId = spl_object_id($first);
        $firstDeltaId = spl_object_id($first->data);

        for ($i = 1; $i < $ringSize; $i++) {
            $pool->tokenDelta("fill-$i", (float) $i, $usage, 0);
        }

        $recycled = $pool->tokenDelta('recycled', 99.0, $usage, 5);

        self::assertSame($firstEventId, spl_object_id($recycled));
        self::assertSame($firstDeltaId, spl_object_id($recycled->data));
        self::assertSame('recycled', $recycled->data->text);
    }

    public function testTokenCompleteProducesCorrectEvent(): void
    {
        $pool = new StreamEventPool();
        $usage = new TokenUsage(input: 100, output: 50);

        $event = $pool->tokenComplete(5.0, $usage, 2);

        self::assertSame(AgentEventKind::TokenComplete, $event->kind);
        self::assertNull($event->data);
        self::assertSame(5.0, $event->elapsed);
        self::assertSame($usage, $event->usageSoFar);
        self::assertSame(2, $event->step);
    }

    public function testToolCallConvenienceMethods(): void
    {
        $pool = new StreamEventPool();
        $usage = TokenUsage::zero();
        $data = new ToolCallData('call-1', 'search');

        $start = $pool->toolCallStart($data, 1.0, $usage, 0);
        self::assertSame(AgentEventKind::ToolCallStart, $start->kind);
        self::assertSame($data, $start->data);

        $completeData = new ToolCallData('call-1', 'search', ['q' => 'Zeus']);
        $complete = $pool->toolCallComplete($completeData, 2.0, $usage, 0);
        self::assertSame(AgentEventKind::ToolCallComplete, $complete->kind);
    }

    public function testErrorConvenienceMethod(): void
    {
        $pool = new StreamEventPool();
        $usage = new TokenUsage(input: 50, output: 10);
        $exception = new \RuntimeException('Poseidon unleashed');

        $event = $pool->error($exception, 3.5, $usage, 1);

        self::assertSame(AgentEventKind::AgentError, $event->kind);
        self::assertSame($exception, $event->data);
        self::assertSame(3.5, $event->elapsed);
        self::assertSame($usage, $event->usageSoFar);
        self::assertSame(1, $event->step);
    }

    public function testIndependentCursorAdvancement(): void
    {
        $pool = new StreamEventPool();
        $usage = TokenUsage::zero();

        $event1 = $pool->event(AgentEventKind::LlmStart, null, 0.0, $usage, 0);
        $delta1 = $pool->delta(text: 'first');
        $event2 = $pool->event(AgentEventKind::TokenDelta, $delta1, 1.0, $usage, 0);

        self::assertNotSame(spl_object_id($event1), spl_object_id($delta1));
        self::assertNotSame(spl_object_id($event1), spl_object_id($event2));
    }

    public function testMultipleEventTypesThroughSameRing(): void
    {
        $pool = new StreamEventPool();
        $usage = TokenUsage::zero();

        $events = [
            $pool->llmStart(0, 0.0),
            $pool->tokenDelta('Hephaestus', 1.0, $usage, 0),
            $pool->tokenComplete(2.0, $usage, 0),
            $pool->stepComplete(0, 3.0, $usage),
            $pool->complete('done', 4.0, $usage, 1),
        ];

        $expectedKinds = [
            AgentEventKind::LlmStart,
            AgentEventKind::TokenDelta,
            AgentEventKind::TokenComplete,
            AgentEventKind::StepComplete,
            AgentEventKind::AgentComplete,
        ];

        foreach ($events as $i => $event) {
            self::assertSame($expectedKinds[$i], $event->kind, "Event $i kind mismatch");
        }
    }

    public function testFullRingCyclePreservesLatestState(): void
    {
        $pool = new StreamEventPool();
        $ringSize = self::ringSize();
        $usage = TokenUsage::zero();

        for ($i = 0; $i < $ringSize * 3; $i++) {
            $pool->tokenDelta("token-$i", (float) $i, $usage, $i % 10);
        }

        $finalUsage = new TokenUsage(input: 999, output: 111);
        $last = $pool->tokenDelta('final', 999.0, $finalUsage, 7);

        self::assertSame(AgentEventKind::TokenDelta, $last->kind);
        self::assertInstanceOf(TokenDelta::class, $last->data);
        self::assertSame('final', $last->data->text);
        self::assertSame(999.0, $last->elapsed);
        self::assertSame($finalUsage, $last->usageSoFar);
        self::assertSame(7, $last->step);
    }
}
