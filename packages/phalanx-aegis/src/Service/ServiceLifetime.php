<?php

declare(strict_types=1);

namespace Phalanx\Service;

enum ServiceLifetime: string
{
    case Singleton = 'singleton';
    case Scoped = 'scoped';
}
