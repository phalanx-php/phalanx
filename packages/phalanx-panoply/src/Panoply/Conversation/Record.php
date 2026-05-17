<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation;

/**
 * Base type for normalized conversation records. Concrete subclasses
 * (Message, ToolCall, ToolResult, Attachment, FileSnapshot, …) are
 * defined alongside the conversation parser implementations.
 */
abstract class Record
{
}
