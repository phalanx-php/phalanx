<?php

declare(strict_types=1);

namespace Phalanx\Athena\Message;

enum ContentKind: string
{
    case Text = 'text';
    case Image = 'image';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
}
