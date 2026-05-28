<?php

declare(strict_types=1);

namespace AegisSwoole\Service;

enum ServiceLifetime: string
{
    case Singleton = 'singleton';
    case Scoped = 'scoped';
}
