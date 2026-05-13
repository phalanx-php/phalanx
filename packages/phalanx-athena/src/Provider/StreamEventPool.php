<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Event\StructuredData;
use Phalanx\Athena\Event\TokenDelta;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Event\ToolCallData;

final class StreamEventPool
{
    private const int RING_SIZE = 64;

    /** @var list<AgentEvent> */
    private array $events = [];

    private int $eventCursor = 0;

    /** @var list<TokenDelta> */
    private array $deltas = [];

    private int $deltaCursor = 0;

    public function event(AgentEventKind $kind, mixed $data, float $elapsed, TokenUsage $usageSoFar, int $step, ?string $agent = null): AgentEvent
    {
        $idx = $this->eventCursor % self::RING_SIZE;

        if ($this->eventCursor < self::RING_SIZE) {
            $this->events[] = new AgentEvent($kind, $data, $elapsed, $usageSoFar, $step, $agent);
        } else {
            $this->events[$idx]->reset($kind, $data, $elapsed, $usageSoFar, $step, $agent);
        }

        $this->eventCursor++;

        return $this->events[$idx];
    }

    public function delta(?string $text = null, ?string $toolCallId = null, ?string $toolName = null, ?string $toolInputJson = null): TokenDelta
    {
        $idx = $this->deltaCursor % self::RING_SIZE;

        if ($this->deltaCursor < self::RING_SIZE) {
            $this->deltas[] = new TokenDelta($text, $toolCallId, $toolName, $toolInputJson);
        } else {
            $this->deltas[$idx]->reset($text, $toolCallId, $toolName, $toolInputJson);
        }

        $this->deltaCursor++;

        return $this->deltas[$idx];
    }

    public function tokenDelta(string $text, float $elapsed, TokenUsage $usage, int $step): AgentEvent
    {
        return $this->event(AgentEventKind::TokenDelta, $this->delta(text: $text), $elapsed, $usage, $step);
    }

    public function tokenComplete(float $elapsed, TokenUsage $usage, int $step): AgentEvent
    {
        return $this->event(AgentEventKind::TokenComplete, null, $elapsed, $usage, $step);
    }

    public function toolCallStart(ToolCallData $data, float $elapsed, TokenUsage $usage, int $step): AgentEvent
    {
        return $this->event(AgentEventKind::ToolCallStart, $data, $elapsed, $usage, $step);
    }

    public function toolCallComplete(ToolCallData $data, float $elapsed, TokenUsage $usage, int $step): AgentEvent
    {
        return $this->event(AgentEventKind::ToolCallComplete, $data, $elapsed, $usage, $step);
    }

    public function llmStart(int $step, float $elapsed): AgentEvent
    {
        return $this->event(AgentEventKind::LlmStart, null, $elapsed, TokenUsage::zero(), $step);
    }

    public function complete(mixed $data, float $elapsed, TokenUsage $usage, int $step): AgentEvent
    {
        return $this->event(AgentEventKind::AgentComplete, $data, $elapsed, $usage, $step);
    }

    public function error(\Throwable $e, float $elapsed, TokenUsage $usage, int $step): AgentEvent
    {
        return $this->event(AgentEventKind::AgentError, $e, $elapsed, $usage, $step);
    }

    public function escalation(string $reason, float $elapsed, TokenUsage $usage, int $step): AgentEvent
    {
        return $this->event(AgentEventKind::Escalation, $reason, $elapsed, $usage, $step);
    }

    public function stepComplete(int $step, float $elapsed, TokenUsage $usage): AgentEvent
    {
        return $this->event(AgentEventKind::StepComplete, null, $elapsed, $usage, $step);
    }

    public function structuredOutput(StructuredData $data, float $elapsed, TokenUsage $usage, int $step): AgentEvent
    {
        return $this->event(AgentEventKind::StructuredOutput, $data, $elapsed, $usage, $step);
    }
}
