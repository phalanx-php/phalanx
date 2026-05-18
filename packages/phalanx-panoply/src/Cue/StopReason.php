<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Cue;

/**
 * Why model output stopped. Used by {@see Output\TokenStop}.
 */
enum StopReason: string
{
    case EndOfTurn    = 'end-of-turn';
    case MaxTokens    = 'max-tokens';
    case StopSequence = 'stop-sequence';
    case ToolUse      = 'tool-use';
    case Error        = 'error';
    case Cancelled    = 'cancelled';
}
