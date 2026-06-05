<?php

declare(strict_types=1);

namespace Phalanx\Agent\Turn;

enum Outcome: string
{
    case Continue = 'continue';
    case Complete = 'complete';
    case WaitingForApproval = 'waiting-for-approval';
    case MaxInvocationsReached = 'max-invocations-reached';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function terminal(): bool
    {
        return $this !== self::Continue;
    }
}
