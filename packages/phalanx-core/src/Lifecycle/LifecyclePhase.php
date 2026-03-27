<?php

declare(strict_types=1);

namespace Phalanx\Lifecycle;

enum LifecyclePhase: string
{
    case Init = 'init';
    case Starting = 'starting';
    case Startup = 'startup';
    case Ready = 'ready';
    case Dispose = 'dispose';
    case Shutdown = 'shutdown';
}
