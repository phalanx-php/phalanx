<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Messages;

enum MessageKind: string
{
    case Task = 'task';
    case Order = 'order';
    case Alert = 'alert';
    case Prompt = 'prompt';
    case Denial = 'denial';
    case Approval = 'approval';
    case Response = 'response';
    case Feedback = 'feedback';
    case PlanUpdate = 'plan_update';
    case ToolResult = 'tool_result';
    case TaskResult = 'task_result';
    case Observation = 'observation';
    case ToolRequest = 'tool_request';
    case StatusUpdate = 'status_update';
}
