<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Events;

enum EventKind: string
{
    case LoopAdvanced = 'loop_advanced';
    case WorkReceived = 'work_received';
    case WorkPrepared = 'work_prepared';
    
    case WorkDistributed = 'work_distributed';
    case WorkItemStarted = 'work_item_started';
    
    case EffectDenied = 'effect_denied';
    case EffectApproved = 'effect_approved';
    case EffectRequested = 'effect_requested';
    
    case WorkReviewed = 'work_reviewed';
    case WorkCompleted = 'work_completed';
    case WorkInterrupted = 'work_interrupted';
    case WorkItemCompleted = 'work_item_completed';
}
