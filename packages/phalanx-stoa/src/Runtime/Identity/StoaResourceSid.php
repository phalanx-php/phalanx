<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeResourceId;

enum StoaResourceSid: string implements RuntimeResourceId
{
    case HttpRequest = 'stoa.http_request';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
