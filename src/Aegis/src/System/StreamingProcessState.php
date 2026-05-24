<?php

declare(strict_types=1);

namespace Phalanx\System;

enum StreamingProcessState: string
{
    case Created = 'created';
    case Running = 'running';
    case Stopping = 'stopping';
    case Exited = 'exited';
    case Failed = 'failed';
    case Killed = 'killed';
    case Closed = 'closed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Exited, self::Failed, self::Killed, self::Closed => true,
            default => false,
        };
    }
}
