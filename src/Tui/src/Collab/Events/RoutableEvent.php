<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Events;

use DateTimeImmutable;
use Phalanx\Tui\Collab\Messages\Envelope;
use Phalanx\Tui\Collab\Plans\WorkItem;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\Reviews\ReviewVerdict;

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
