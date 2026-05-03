<?php

declare(strict_types=1);

namespace Phalanx\Archon\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeEventId;

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
