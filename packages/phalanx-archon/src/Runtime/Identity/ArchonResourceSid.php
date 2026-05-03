<?php

declare(strict_types=1);

namespace Phalanx\Archon\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeResourceId;

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
