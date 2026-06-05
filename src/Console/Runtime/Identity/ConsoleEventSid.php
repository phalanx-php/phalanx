<?php

declare(strict_types=1);

namespace Phalanx\Console\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeEventId;

/**
 * Stable identifiers for the lifecycle events Console emits on the
 * supervisor's runtime event stream (dispatch, match, completion,
 * failure, abort). Diagnostics consumers query by these ids rather than
 * by free-form strings.
 */
enum ConsoleEventSid: string implements RuntimeEventId
{
    case CommandAborted = 'console.command.aborted';
    case CommandCompleted = 'console.command.completed';
    case CommandDispatched = 'console.command.dispatched';
    case CommandFailed = 'console.command.failed';
    case CommandInvalidInput = 'console.command.invalid_input';
    case CommandMatched = 'console.command.matched';
    case CommandUnknown = 'console.command.unknown';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
