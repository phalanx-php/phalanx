<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Apps;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Theatron\Collab\Boundaries\BoundaryRunner;
use Phalanx\Theatron\Collab\Boundaries\Inlet;
use Phalanx\Theatron\Collab\Boundaries\InletChannel;
use Phalanx\Theatron\Collab\Boundaries\InletQueue;
use Phalanx\Theatron\Collab\Boundaries\InputPromptSubmitter;
use Phalanx\Theatron\Collab\Boundaries\Outlet;
use Phalanx\Theatron\Collab\Boundaries\OutletReactor;
use Phalanx\Theatron\Collab\Lifecycle\AgentHarnessLoop;
use Phalanx\Theatron\Collab\Participants\AgentParticipant;
use Phalanx\Theatron\Collab\Participants\Preparer;
use Phalanx\Theatron\Collab\Participants\Reactor;
use Phalanx\Theatron\Collab\Participants\Reviewer;
use Phalanx\Theatron\Collab\State\AgentHarnessStore;

final class AgentHarnessServiceBundle extends ServiceBundle
{
    /**
     * @param list<Preparer> $preparers
     * @param list<AgentParticipant> $participants
     * @param list<Reactor> $reactors
     * @param list<Reviewer> $reviewers
     * @param list<Inlet> $inlets
     * @param list<Outlet> $outlets
     */
    public function __construct(
        private AgentParticipant $primary,
        private array $preparers = [],
        private array $participants = [],
        private array $reactors = [],
        private array $reviewers = [],
        private array $inlets = [],
        private array $outlets = [],
        private int $maxReviewPasses = 8,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $primary = $this->primary;
        $inlets = $this->inlets;
        $outlets = $this->outlets;
        $reactors = $this->reactors;
        $reviewers = $this->reviewers;
        $preparers = $this->preparers;
        $participants = $this->participants;
        $maxReviewPasses = $this->maxReviewPasses;

        $services->singleton(InletQueue::class)
            ->factory(static fn(): InletQueue => new InletQueue());

        $services->alias(InletChannel::class, InletQueue::class);

        $services->singleton(InputPromptSubmitter::class)
            ->needs(InletQueue::class)
            ->factory(static fn(InletQueue $incoming): InputPromptSubmitter => new InputPromptSubmitter($incoming));

        $services
            ->singleton(AgentHarnessLoop::class)
            ->factory(static function () use (
                $primary,
                $outlets,
                $reactors,
                $reviewers,
                $preparers,
                $participants,
                $maxReviewPasses,
            ): AgentHarnessLoop {
                if ($outlets !== []) {
                    $reactors = [...$reactors, new OutletReactor($outlets)];
                }

                return new AgentHarnessLoop(
                    primary: $primary,
                    preparers: $preparers,
                    participants: $participants,
                    reactors: $reactors,
                    reviewers: $reviewers,
                    maxReviewPasses: $maxReviewPasses,
                );
            });

        $services->singleton(BoundaryRunner::class)
            ->needs(AgentHarnessLoop::class, InletQueue::class)
            ->factory(static fn(AgentHarnessLoop $loop, InletQueue $incoming): BoundaryRunner => new BoundaryRunner(
                loop: $loop,
                inlets: $inlets,
                incoming: $incoming,
            ));

        $services->singleton(AgentHarnessRuntime::class)
            ->needs(BoundaryRunner::class, AgentHarnessStore::class)
            ->factory(static fn(BoundaryRunner $runner, AgentHarnessStore $store): AgentHarnessRuntime => new AgentHarnessRuntime(
                runner: $runner,
                store: $store,
            ));
    }
}
