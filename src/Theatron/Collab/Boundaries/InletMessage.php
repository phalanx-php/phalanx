<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Boundaries;

use DateTimeImmutable;
use Phalanx\Theatron\Collab\Messages\Envelope;

final class InletMessage
{
    private(set) DateTimeImmutable $receivedAt;

    public function __construct(
        private(set) Envelope $envelope,
        private(set) Urgency $urgency = Urgency::Queue,
        ?DateTimeImmutable $receivedAt = null,
    ) {
        $this->receivedAt = $receivedAt ?? new DateTimeImmutable();
    }
}
