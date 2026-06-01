<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\State;

final class ParticipantSlice
{
    /** @var list<string> */
    private(set) array $participants;

    /**
     * @param list<string> $participants
     */
    public function __construct(array $participants = [])
    {
        $this->participants = array_values($participants);
    }
}
