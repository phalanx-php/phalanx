<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Signal;

enum AgenticSignalType: string
{
    case Thinking    = 'agent.thinking';
    case ToolCall    = 'agent.tool_call';
    case ToolResult  = 'agent.tool_result';
    case FinalAnswer = 'agent.final_answer';
    case UiIntent    = 'agent.ui_intent';
    case Branch      = 'agent.branch';
}
