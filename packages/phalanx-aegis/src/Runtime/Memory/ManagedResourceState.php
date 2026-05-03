<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

enum ManagedResourceState: string
{
    case Opening = 'opening';
    case Active = 'active';
    case Closing = 'closing';
    case Closed = 'closed';
    case Aborting = 'aborting';
    case Aborted = 'aborted';
    case Failing = 'failing';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Closed, self::Aborted, self::Failed => true,
            default => false,
        };
    }
}
