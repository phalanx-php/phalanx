<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

enum DevToolsMode: string
{
    case Docked = 'docked';
    case Overlay = 'overlay';
    case Hidden = 'hidden';
}
