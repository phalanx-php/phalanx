<?php

declare(strict_types=1);

namespace Phalanx\Harness\Event;

use DateTimeImmutable;
use Phalanx\Harness\Message\Envelope;
use Phalanx\Harness\Review\ReviewVerdict;
use Phalanx\Harness\Work\WorkItem;
use Phalanx\Harness\Work\WorkResult;

final class RoutableEvent
{
    public function __construct(
        private(set) string $id,
        private(set) EventKind $kind,
        private(set) DateTimeImmutable $occurredAt,
        private(set) ?Envelope $envelope = null,
        private(set) ?WorkItem $workItem = null,
        private(set) ?WorkResult $workResult = null,
        private(set) ?ReviewVerdict $reviewVerdict = null,
        private(set) ?string $summary = null,
    ) {
        if (trim($this->id) === '') {
            throw new \InvalidArgumentException('Routable event id cannot be empty.');
        }
    }
}
