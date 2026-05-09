<?php

declare(strict_types=1);

namespace Acme;

enum TicketCategory: string
{
    case Billing = 'billing';
    case Technical = 'technical';
    case Account = 'account';
    case FeatureRequest = 'feature-request';
    case BugReport = 'bug-report';
}
