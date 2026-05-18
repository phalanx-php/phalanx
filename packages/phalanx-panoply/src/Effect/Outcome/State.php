<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Effect\Outcome;

/**
 * Terminal state of a completed Outcome. Drives which fields are populated
 * on the Outcome value object and which predicates return true.
 */
enum State: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
