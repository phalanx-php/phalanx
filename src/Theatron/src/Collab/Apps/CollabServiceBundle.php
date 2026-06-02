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
use Phalanx\Theatron\Collab\Lifecycle\CollaborationLoop;
use Phalanx\Theatron\Collab\Participants\Collaborator;
use Phalanx\Theatron\Collab\Participants\Preparer;
use Phalanx\Theatron\Collab\Participants\Reactor;
use Phalanx\Theatron\Collab\Participants\Reviewer;
use Phalanx\Theatron\Collab\State\CollabStore;

final class CollabServiceBundle extends ServiceBundle
{
    /**
     * @param list<Preparer> $preparers
     * @param list<Collaborator> $collaborators
     * @param list<Reactor> $reactors
     * @param list<Reviewer> $reviewers
     * @param list<Inlet> $inlets
     * @param list<Outlet> $outlets
     */
    public function __construct(
        private Collaborator $primary,
        private array $preparers = [],
        private array $collaborators = [],
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
        $collaborators = $this->collaborators;
        $maxReviewPasses = $this->maxReviewPasses;

        $services->singleton(InletQueue::class)
            ->factory(static fn(): InletQueue => new InletQueue());

        $services->alias(InletChannel::class, InletQueue::class);

        $services->singleton(InputPromptSubmitter::class)
            ->needs(InletQueue::class)
            ->factory(static fn(InletQueue $incoming): InputPromptSubmitter => new InputPromptSubmitter($incoming));

        $services
            ->singleton(CollaborationLoop::class)
            ->factory(static function () use (
                $primary,
                $outlets,
                $reactors,
                $reviewers,
                $preparers,
                $collaborators,
                $maxReviewPasses,
            ): CollaborationLoop {
                if ($outlets !== []) {
                    $reactors = [...$reactors, new OutletReactor($outlets)];
                }

                return new CollaborationLoop(
                    primary: $primary,
                    preparers: $preparers,
                    collaborators: $collaborators,
                    reactors: $reactors,
                    reviewers: $reviewers,
                    maxReviewPasses: $maxReviewPasses,
                );
            });

        $services->singleton(BoundaryRunner::class)
            ->needs(CollaborationLoop::class, InletQueue::class)
            ->factory(static fn(CollaborationLoop $loop, InletQueue $incoming): BoundaryRunner => new BoundaryRunner(
                loop: $loop,
                inlets: $inlets,
                incoming: $incoming,
            ));

        $services->singleton(CollabRuntime::class)
            ->needs(BoundaryRunner::class, CollabStore::class)
            ->factory(static fn(BoundaryRunner $runner, CollabStore $store): CollabRuntime => new CollabRuntime(
                runner: $runner,
                store: $store,
            ));
    }
}
