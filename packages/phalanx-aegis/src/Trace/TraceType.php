<?php

declare(strict_types=1);

namespace Phalanx\Trace;

enum TraceType: string
{
    case Execute = 'execute';
    case Retry = 'retry';
    case Timeout = 'timeout';
    case Defer = 'defer';
    case Singleflight = 'singleflight';
    case ServiceResolve = 'service.resolve';
    case Failed = 'failed';
    case LifecycleStartup = 'lifecycle.startup';
    case LifecycleShutdown = 'lifecycle.shutdown';
    case Worker = 'worker';
}
