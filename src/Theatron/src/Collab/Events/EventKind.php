<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Events;

enum EventKind: string
{
    case WorkReceived = 'work_received';
    case WorkPrepared = 'work_prepared';
    case WorkDistributed = 'work_distributed';
    case WorkItemStarted = 'work_item_started';
    case EffectRequested = 'effect_requested';
    case EffectApproved = 'effect_approved';
    case EffectDenied = 'effect_denied';
    case WorkItemCompleted = 'work_item_completed';
    case WorkInterrupted = 'work_interrupted';
    case WorkReviewed = 'work_reviewed';
    case WorkCompleted = 'work_completed';
}
