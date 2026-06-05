<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Apps;

use Phalanx\Tui\Collab\Boundaries\Inlet;
use Phalanx\Tui\Collab\Boundaries\Outlet;
use Phalanx\Tui\Collab\Participants\AgentParticipant;
use Phalanx\Tui\Collab\Participants\Preparer;
use Phalanx\Tui\Collab\Participants\Reactor;
use Phalanx\Tui\Collab\Participants\Reviewer;

final class Definition
{
    /** @var list<Preparer> */
    private(set) array $preparers;

    /** @var list<AgentParticipant> */
    private(set) array $participants;

    /** @var list<Reactor> */
    private(set) array $reactors;

    /** @var list<Reviewer> */
    private(set) array $reviewers;

    /** @var list<Inlet> */
    private(set) array $inlets;

    /** @var list<Outlet> */
    private(set) array $outlets;

    /**
     * @param list<Preparer> $preparers
     * @param list<AgentParticipant> $participants
     * @param list<Reactor> $reactors
     * @param list<Reviewer> $reviewers
     * @param list<Inlet> $inlets
     * @param list<Outlet> $outlets
     */
    public function __construct(
        private(set) AgentParticipant $primary,
        array $preparers = [],
        array $participants = [],
        array $reactors = [],
        array $reviewers = [],
        array $inlets = [],
        array $outlets = [],
        private(set) int $maxReviewPasses = 8,
    ) {
        if ($this->maxReviewPasses < 1) {
            throw new \InvalidArgumentException('AgentHarness max review passes must be >= 1.');
        }

        $this->inlets = array_values($inlets);
        $this->outlets = array_values($outlets);
        $this->reactors = array_values($reactors);
        $this->reviewers = array_values($reviewers);
        $this->preparers = array_values($preparers);
        $this->participants = array_values($participants);
    }
}
