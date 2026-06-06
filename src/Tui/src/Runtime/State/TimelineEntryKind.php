<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\State;

enum TimelineEntryKind: string
{
    case Prompt = 'prompt';
    case Response = 'response';
    case Message = 'message';
    case WorkStarted = 'work_started';
    case WorkCompleted = 'work_completed';
    case WorkInterrupted = 'work_interrupted';
    case Review = 'review';
}
