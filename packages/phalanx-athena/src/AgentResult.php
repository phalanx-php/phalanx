<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Athena\Stream\Generation;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Emitter;

final readonly class AgentResult
{
    public function __construct(
        public string $text,
        public ?object $structured,
        public Conversation $conversation,
        public TokenUsage $usage,
        public int $steps,
    ) {}

    public static function fromGeneration(Generation $generation, Conversation $conversation, int $steps): self
    {
        return new self(
            text: $generation->text,
            structured: null,
            conversation: $conversation,
            usage: $generation->usage,
            steps: $steps,
        );
    }

    public static function fromTool(ToolOutcome $outcome, Conversation $conversation, TokenUsage $usage, int $steps): self
    {
        $text = is_string($outcome->data) ? $outcome->data : json_encode($outcome->data, JSON_THROW_ON_ERROR);

        return new self(
            text: $text,
            structured: null,
            conversation: $conversation,
            usage: $usage,
            steps: $steps,
        );
    }

    public static function maxStepsReached(Conversation $conversation, TokenUsage $usage, int $steps): self
    {
        return new self(
            text: '',
            structured: null,
            conversation: $conversation,
            usage: $usage,
            steps: $steps,
        );
    }

    public static function awaitFrom(Emitter $events, ExecutionScope $ctx): self
    {
        $lastResult = null;

        foreach ($events($ctx) as $event) {
            if ($event instanceof AgentEvent && $event->kind === AgentEventKind::AgentComplete) {
                $lastResult = $event->data;
            }
        }

        if ($lastResult instanceof self) {
            return $lastResult;
        }

        throw new \RuntimeException('Agent completed without producing a result');
    }

    /** @return array{text: string, usage: array{input: int, output: int, total: int}, steps: int} */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'usage' => $this->usage->toArray(),
            'steps' => $this->steps,
        ];
    }
}
