<?php

declare(strict_types=1);

namespace Phalanx\Ui\Signal;

enum SignalType: string
{
    case Invalidate = 'invalidate';
    case Flash      = 'flash';
    case Redirect   = 'redirect';
    case Event      = 'event';
    case Token      = 'token';
}
