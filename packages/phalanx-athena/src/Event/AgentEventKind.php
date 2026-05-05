<?php

declare(strict_types=1);

namespace Phalanx\Athena\Event;

enum AgentEventKind: string
{
    case LlmStart = 'llm.start';
    case TokenDelta = 'token.delta';
    case TokenComplete = 'token.complete';
    case ToolCallStart = 'tool_call.start';
    case ToolCallComplete = 'tool_call.complete';
    case StepComplete = 'step.complete';
    case StructuredOutput = 'structured_output';
    case AgentComplete = 'agent.complete';
    case AgentError = 'agent.error';
    case Escalation = 'escalation';

    /** @return list<self> */
    public static function any(): array
    {
        return self::cases();
    }

    public function isUserFacing(): bool
    {
        return match ($this) {
            self::TokenDelta,
            self::ToolCallStart,
            self::ToolCallComplete,
            self::StructuredOutput,
            self::AgentComplete,
            self::AgentError,
            self::Escalation => true,
            default => false,
        };
    }

    public function isTool(): bool
    {
        return match ($this) {
            self::ToolCallStart, self::ToolCallComplete => true,
            default => false,
        };
    }
}
