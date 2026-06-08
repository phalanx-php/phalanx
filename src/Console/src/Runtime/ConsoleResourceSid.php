<?php

declare(strict_types=1);

namespace Phalanx\Console\Runtime;

use Phalanx\Runtime\Identity\RuntimeResourceId;

/**
 * Stable identifiers for managed resource kinds owned by Console. The only
 * kind today is `console.command` — one resource per dispatched command,
 * opened on entry to the command scope and closed on exit. Runtime tracks
 * the open/close transitions for leak detection.
 */
enum ConsoleResourceSid: string implements RuntimeResourceId
{
    case Command = 'console.command';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
