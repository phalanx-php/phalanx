<?php

declare(strict_types=1);

namespace Phalanx\Harness\Message;

enum MessageKind: string
{
    case Prompt = 'prompt';
    case Response = 'response';
    case ToolRequest = 'tool_request';
    case ToolResult = 'tool_result';
    case Task = 'task';
    case TaskResult = 'task_result';
    case Order = 'order';
    case Feedback = 'feedback';
    case Observation = 'observation';
    case Alert = 'alert';
    case Approval = 'approval';
    case Denial = 'denial';
    case PlanUpdate = 'plan_update';
    case StatusUpdate = 'status_update';
}
