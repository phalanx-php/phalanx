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
use Phalanx\Tui\Collab\Lifecycle\Loop;
use Phalanx\Tui\Collab\State\Store;

final class Bundle extends ServiceBundle
{
    public function __construct(
        private Definition $definition,
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
            ->singleton(Loop::class)
            ->factory(static function () use ($definition): Loop {
                $reactors = $definition->reactors;
                if ($definition->outlets !== []) {
                    $reactors = [...$reactors, new OutletReactor($definition->outlets)];
                }

                return new Loop(
                    primary: $definition->primary,
                    preparers: $definition->preparers,
                    participants: $definition->participants,
                    reactors: $reactors,
                    reviewers: $definition->reviewers,
                    maxReviewPasses: $definition->maxReviewPasses,
                );
            });

        $services->singleton(BoundaryRunner::class)
            ->needs(Loop::class, InletQueue::class)
            ->factory(static fn(Loop $loop, InletQueue $incoming): BoundaryRunner => new BoundaryRunner(
                loop: $loop,
                inlets: $definition->inlets,
                incoming: $incoming,
            ));

        $services->singleton(Runtime::class)
            ->needs(BoundaryRunner::class, Store::class)
            ->factory(static fn(BoundaryRunner $runner, Store $store): Runtime => new Runtime(
                runner: $runner,
                store: $store,
            ));
    }
}
