<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

enum ResumeStatus: string
{
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Ready = 'ready';
    case Streaming = 'streaming';
    case WaitingApproval = 'waiting_approval';
}
