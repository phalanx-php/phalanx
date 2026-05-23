<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeAnnotationId;

enum StoaAnnotationSid: string implements RuntimeAnnotationId
{
    case Fd = 'stoa.fd';
    case ListenHost = 'stoa.listen_host';
    case ListenPort = 'stoa.listen_port';
    case Method = 'stoa.method';
    case Path = 'stoa.path';
    case Route = 'stoa.route';
    case Status = 'stoa.status';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
