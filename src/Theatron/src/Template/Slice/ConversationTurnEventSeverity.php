<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

enum ConversationTurnEventSeverity: string
{
    case Error = 'error';
    case Info = 'info';
    case Muted = 'muted';
    case Success = 'success';
    case Warning = 'warning';
}
