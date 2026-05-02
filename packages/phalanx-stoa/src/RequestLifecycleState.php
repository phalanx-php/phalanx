<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

enum RequestLifecycleState: string
{
    case Opened = 'opened';
    case HeadersStarted = 'headers_started';
    case BodyStarted = 'body_started';
    case Completed = 'completed';
    case Failed = 'failed';
    case Aborted = 'aborted';
}
