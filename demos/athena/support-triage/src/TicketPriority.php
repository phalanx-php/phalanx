<?php

declare(strict_types=1);

namespace Acme;

enum TicketPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
