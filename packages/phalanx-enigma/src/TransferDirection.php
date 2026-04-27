<?php

declare(strict_types=1);

namespace Phalanx\Enigma;

enum TransferDirection: string
{
    case Upload = 'upload';
    case Download = 'download';
}
