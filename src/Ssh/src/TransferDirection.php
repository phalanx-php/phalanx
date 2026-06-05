<?php

declare(strict_types=1);

namespace Phalanx\Ssh;

enum TransferDirection: string
{
    case Upload = 'upload';
    case Download = 'download';
}
