<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

enum DevToolsTab: string
{
    case Metrics = 'Metrics';
    case Requests = 'Requests';
    case Signals = 'Signals';
    case Tree = 'Tree';
    case Store = 'Store';
}
