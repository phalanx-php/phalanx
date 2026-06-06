<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Apps;

use Phalanx\Tui\Runtime\Boundaries\Inlet;
use Phalanx\Tui\Runtime\Boundaries\Outlet;
use Phalanx\Tui\Runtime\Participants\AgentParticipant;
use Phalanx\Tui\Runtime\Participants\Preparer;
use Phalanx\Tui\Runtime\Participants\Reactor;
use Phalanx\Tui\Runtime\Participants\Reviewer;

final class Definition
{
    /** @var list<Inlet> */
    private(set) array $inlets;

    /** @var list<Outlet> */
    private(set) array $outlets;

    /** @var list<Reactor> */
    private(set) array $reactors;

    /** @var list<Preparer> */
    private(set) array $preparers;

    /** @var list<Reviewer> */
    private(set) array $reviewers;

    /** @var list<AgentParticipant> */
    private(set) array $participants;

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
            throw new \InvalidArgumentException('TUI runtime max review passes must be >= 1.');
        }

        $this->inlets = array_values($inlets);
        $this->outlets = array_values($outlets);
        $this->reactors = array_values($reactors);
        $this->reviewers = array_values($reviewers);
        $this->preparers = array_values($preparers);
        $this->participants = array_values($participants);
    }
}
