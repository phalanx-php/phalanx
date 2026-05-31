<?php

declare(strict_types=1);

namespace Phalanx\Harness\Boundary;

use DateTimeImmutable;
use Phalanx\Harness\Message\Envelope;

final class InletMessage
{
    public function __construct(
        private(set) Envelope $envelope,
        private(set) Urgency $urgency = Urgency::Queue,
        private(set) ?DateTimeImmutable $receivedAt = null,
    ) {
        $this->receivedAt ??= new DateTimeImmutable();
    }
}
