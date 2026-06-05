<?php

declare(strict_types=1);

namespace Phalanx\Http\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeAnnotationId;

enum HttpAnnotationSid: string implements RuntimeAnnotationId
{
    case Fd = 'http.fd';
    case ListenHost = 'http.listen_host';
    case ListenPort = 'http.listen_port';
    case Method = 'http.method';
    case Path = 'http.path';
    case Route = 'http.route';
    case Status = 'http.status';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
