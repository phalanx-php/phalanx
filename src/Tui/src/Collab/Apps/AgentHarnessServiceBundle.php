<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Apps;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Tui\Collab\Boundaries\BoundaryRunner;
use Phalanx\Tui\Collab\Boundaries\InletChannel;
use Phalanx\Tui\Collab\Boundaries\InletQueue;
use Phalanx\Tui\Collab\Boundaries\InputPromptSubmitter;
use Phalanx\Tui\Collab\Boundaries\OutletReactor;
use Phalanx\Tui\Collab\Lifecycle\AgentHarnessLoop;
use Phalanx\Tui\Collab\State\AgentHarnessStore;

final class AgentHarnessServiceBundle extends ServiceBundle
{
    public function __construct(
        private AgentHarnessDefinition $definition,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $definition = $this->definition;

        $services->singleton(InletQueue::class)
            ->factory(static fn(): InletQueue => new InletQueue());

        $services->alias(InletChannel::class, InletQueue::class);

        $services->singleton(InputPromptSubmitter::class)
            ->needs(InletQueue::class)
            ->factory(static fn(InletQueue $incoming): InputPromptSubmitter => new InputPromptSubmitter($incoming));

        $services
            ->singleton(AgentHarnessLoop::class)
            ->factory(static function () use ($definition): AgentHarnessLoop {
                $reactors = $definition->reactors;
                if ($definition->outlets !== []) {
                    $reactors = [...$reactors, new OutletReactor($definition->outlets)];
                }

                return new AgentHarnessLoop(
                    primary: $definition->primary,
                    preparers: $definition->preparers,
                    participants: $definition->participants,
                    reactors: $reactors,
                    reviewers: $definition->reviewers,
                    maxReviewPasses: $definition->maxReviewPasses,
                );
            });

        $services->singleton(BoundaryRunner::class)
            ->needs(AgentHarnessLoop::class, InletQueue::class)
            ->factory(static fn(AgentHarnessLoop $loop, InletQueue $incoming): BoundaryRunner => new BoundaryRunner(
                loop: $loop,
                inlets: $definition->inlets,
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
