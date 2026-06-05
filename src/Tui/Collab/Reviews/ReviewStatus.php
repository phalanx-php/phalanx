<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Reviews;

enum ReviewStatus: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsRevision = 'needs_revision';
}
