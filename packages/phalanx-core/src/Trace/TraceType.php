<?php

declare(strict_types=1);

namespace Phalanx\Trace;

enum TraceType: string
{
    case Executing = 'EXEC';
    case Done = 'DONE';
    case Failed = 'FAIL';
    case ConcurrentStart = 'CON>';
    case ConcurrentEnd = 'CON<';
    case Retry = 'RTRY';
    case Cancelled = 'CANC';
    case ServiceInit = 'SVC+';
    case ServiceDispose = 'SVC-';
    case LifecycleStartup = 'STRT';
    case LifecycleShutdown = 'STOP';
}
