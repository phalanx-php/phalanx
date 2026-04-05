<?php

declare(strict_types=1);

namespace Phalanx\Ai\Event;

use Phalanx\Ai\Message\Conversation;

final readonly class AgentEvent
{
    public function __construct(
        public AgentEventKind $kind,
        public mixed $data,
        public float $elapsed,
        public TokenUsage $usageSoFar,
        public int $step,
    ) {}

    public static function llmStart(int $step, float $elapsed): self
    {
        return new self(AgentEventKind::LlmStart, null, $elapsed, TokenUsage::zero(), $step);
    }

    public static function tokenDelta(TokenDelta $delta, float $elapsed, TokenUsage $usage, int $step): self
    {
        return new self(AgentEventKind::TokenDelta, $delta, $elapsed, $usage, $step);
    }

    public static function tokenComplete(float $elapsed, TokenUsage $usage, int $step): self
    {
        return new self(AgentEventKind::TokenComplete, null, $elapsed, $usage, $step);
    }

    public static function toolCallStart(ToolCallData $data, float $elapsed, TokenUsage $usage, int $step): self
    {
        return new self(AgentEventKind::ToolCallStart, $data, $elapsed, $usage, $step);
    }

    public static function toolCallComplete(ToolCallData $data, float $elapsed, TokenUsage $usage, int $step): self
    {
        return new self(AgentEventKind::ToolCallComplete, $data, $elapsed, $usage, $step);
    }

    public static function stepComplete(int $step, float $elapsed, TokenUsage $usage): self
    {
        return new self(AgentEventKind::StepComplete, null, $elapsed, $usage, $step);
    }

    public static function structuredOutput(StructuredData $data, float $elapsed, TokenUsage $usage, int $step): self
    {
        return new self(AgentEventKind::StructuredOutput, $data, $elapsed, $usage, $step);
    }

    public static function complete(mixed $data, float $elapsed, TokenUsage $usage, int $step): self
    {
        return new self(AgentEventKind::AgentComplete, $data, $elapsed, $usage, $step);
    }

    public static function error(\Throwable $e, float $elapsed, TokenUsage $usage, int $step): self
    {
        return new self(AgentEventKind::AgentError, $e, $elapsed, $usage, $step);
    }

    public static function escalation(string $reason, float $elapsed, TokenUsage $usage, int $step): self
    {
        return new self(AgentEventKind::Escalation, $reason, $elapsed, $usage, $step);
    }

    public function toJson(): string
    {
        return json_encode([
            'kind' => $this->kind->value,
            'data' => $this->data,
            'elapsed' => $this->elapsed,
            'usage' => $this->usageSoFar->toArray(),
            'step' => $this->step,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            kind: AgentEventKind::from($data['kind']),
            data: $data['data'],
            elapsed: (float) $data['elapsed'],
            usageSoFar: TokenUsage::fromArray($data['usage']),
            step: (int) $data['step'],
        );
    }
}
