<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation;

/**
 * Stable string discriminator for every Record subclass. The value goes
 * into `toCanonical()` — not the PHP class name — so renaming a class
 * never breaks replay keys or audit fingerprints.
 */
enum RecordType: string
{
    case Message = 'record.message';
    case ToolCall = 'record.tool_call';
    case ToolResult = 'record.tool_result';
    case Attachment = 'record.attachment';
    case FileSnapshot = 'record.file_snapshot';
    case PermissionMode = 'record.permission_mode';
    case Sidechain = 'record.sidechain';
    case Metadata = 'record.metadata';
    case Error = 'record.error';
    case Unknown = 'record.unknown';
}
