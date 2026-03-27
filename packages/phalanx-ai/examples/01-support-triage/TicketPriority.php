<?php

declare(strict_types=1);

namespace Phalanx\Ai\Examples\SupportTriage;

enum TicketPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
