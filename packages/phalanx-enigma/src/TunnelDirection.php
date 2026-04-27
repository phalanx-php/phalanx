<?php

declare(strict_types=1);

namespace Phalanx\Enigma;

enum TunnelDirection: string
{
    case Local = 'local';
    case Remote = 'remote';
}
