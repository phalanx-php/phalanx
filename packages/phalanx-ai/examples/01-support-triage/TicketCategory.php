<?php

declare(strict_types=1);

namespace Phalanx\Ai\Examples\SupportTriage;

enum TicketCategory: string
{
    case Billing = 'billing';
    case Technical = 'technical';
    case Account = 'account';
    case FeatureRequest = 'feature-request';
    case BugReport = 'bug-report';
}
