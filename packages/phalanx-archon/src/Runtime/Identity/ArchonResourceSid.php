<?php

declare(strict_types=1);

namespace Phalanx\Archon\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeResourceId;

/**
 * Stable identifiers for managed resource kinds owned by Archon. The only
 * kind today is `archon.command` — one resource per dispatched command,
 * opened on entry to the command scope and closed on exit. Aegis tracks
 * the open/close transitions for leak detection.
 */
enum ArchonResourceSid: string implements RuntimeResourceId
{
    case Command = 'archon.command';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
