<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Boundaries;

use Phalanx\Tui\Collab\Events\Event;
use Phalanx\Tui\Collab\Events\EventKind;
use Phalanx\Tui\Collab\Lifecycle\Loop;
use Phalanx\Tui\Collab\Plans\WorkPlanStatus;
use Phalanx\Tui\Collab\WorkContext;

final class BoundaryRunner
{
    /** @var list<Inlet> */
    private array $inlets;

    /** @param iterable<Inlet> $inlets */
    public function __construct(
        private Loop $loop,
        iterable $inlets = [],
        private InletQueue $incoming = new InletQueue(),
        private PromptInletMapper $mapper = new PromptInletMapper(),
    ) {
        $this->inlets = self::inlets($inlets);
    }

    public function __invoke(WorkContext $ctx): WorkPlanStatus
    {
        foreach ($this->inlets as $inlet) {
            $inlet($ctx->scope, $this->incoming);
        }

        $received = false;
        foreach ($this->incoming->drain() as $message) {
            $received = true;
            $item = ($this->mapper)($message);
            $ctx->project(Event::record(
                EventKind::WorkReceived,
                envelope: $message->envelope,
                workItem: $item,
            ));
        }

        if (!$received && $ctx->plan->readyItems() === []) {
            return $ctx->plan->status;
        }

        return ($this->loop)($ctx);
    }

    public function incoming(): InletChannel
    {
        return $this->incoming;
    }

    /**
     * @param iterable<Inlet> $inlets
     * @return list<Inlet>
     */
    private static function inlets(iterable $inlets): array
    {
        $out = [];
        foreach ($inlets as $inlet) {
            if (!$inlet instanceof Inlet) {
                throw new \InvalidArgumentException(sprintf('Expected instances of %s.', Inlet::class));
            }

            $out[] = $inlet;
        }

        return $out;
    }
}
