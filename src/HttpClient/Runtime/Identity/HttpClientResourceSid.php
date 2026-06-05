<?php

declare(strict_types=1);

namespace Phalanx\HttpClient\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeResourceId;

enum HttpClientResourceSid: string implements RuntimeResourceId
{
    case OutboundHttpRequest = 'http-client.outbound_http_request';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
