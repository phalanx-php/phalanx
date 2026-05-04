<?php

declare(strict_types=1);

namespace Phalanx\Archon\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeEventId;

/**
 * Stable identifiers for the lifecycle events Archon emits on the
 * supervisor's runtime event stream (dispatch, match, completion,
 * failure, abort). Diagnostics consumers query by these ids rather than
 * by free-form strings.
 */
enum ArchonEventSid: string implements RuntimeEventId
{
    case CommandAborted = 'archon.command.aborted';
    case CommandCompleted = 'archon.command.completed';
    case CommandDispatched = 'archon.command.dispatched';
    case CommandFailed = 'archon.command.failed';
    case CommandInvalidInput = 'archon.command.invalid_input';
    case CommandMatched = 'archon.command.matched';
    case CommandUnknown = 'archon.command.unknown';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
