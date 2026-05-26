<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

enum ConversationTurnEventSeverity: string
{
    case Error = 'error';
    case Info = 'info';
    case Muted = 'muted';
    case Success = 'success';
    case Warning = 'warning';
}
