<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Events;

use DateTimeImmutable;
use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Reviews\ReviewVerdict;

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
