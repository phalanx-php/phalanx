<?php

declare(strict_types=1);

namespace Phalanx\Iris\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeResourceId;

enum IrisResourceSid: string implements RuntimeResourceId
{
    case OutboundHttpRequest = 'iris.outbound_http_request';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
