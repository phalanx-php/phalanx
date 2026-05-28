<?php

declare(strict_types=1);

namespace BgAgents\Bookkeeper;

enum IssueKind: string
{
    case Duplicate = 'duplicate';
    case Conflict = 'conflict';
    case Stale = 'stale';
    case Contradiction = 'contradiction';
    case Noise = 'noise';
    case ConsolidationProposed = 'consolidation_proposed';
    case PromotionProposed = 'promotion_proposed';
}
