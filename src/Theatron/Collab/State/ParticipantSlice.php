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

    public function register(string ...$participants): self
    {
        $next = $this->participants;

        foreach ($participants as $participant) {
            $participant = trim($participant);
            if ($participant === '') {
                throw new \InvalidArgumentException('Participant id cannot be empty.');
            }

            if (!in_array($participant, $next, true)) {
                $next[] = $participant;
            }
        }

        return new self($next);
    }
}
