<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Harness\Review;

enum ReviewStatus: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsRevision = 'needs_revision';
}
