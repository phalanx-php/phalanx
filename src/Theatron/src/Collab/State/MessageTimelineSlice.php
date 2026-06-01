<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\State;

use Phalanx\Theatron\Collab\Messages\Envelope;

final class MessageTimelineSlice
{
    /** @var list<Envelope> */
    private(set) array $envelopes;

    /**
     * @param list<Envelope> $envelopes
     */
    public function __construct(array $envelopes = [])
    {
        $this->envelopes = array_values($envelopes);
    }

    public function record(Envelope $envelope): self
    {
        return new self([...$this->envelopes, $envelope]);
    }
}
