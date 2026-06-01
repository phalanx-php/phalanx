<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Boundaries;

use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\Participants\Reactor;
use Phalanx\Theatron\Collab\WorkContext;

final class OutletReactor implements Reactor
{
    /** @var list<Outlet> */
    private array $outlets;

    /** @param iterable<Outlet> $outlets */
    public function __construct(iterable $outlets)
    {
        $this->outlets = self::outlets($outlets);
    }

    public function __invoke(CollabEvent $event, WorkContext $ctx): void
    {
        $routable = $event->routable();

        foreach ($this->outlets as $outlet) {
            $outlet($routable, $ctx->scope);
        }
    }

    /**
     * @param iterable<Outlet> $outlets
     * @return list<Outlet>
     */
    private static function outlets(iterable $outlets): array
    {
        $out = [];
        foreach ($outlets as $outlet) {
            if (!$outlet instanceof Outlet) {
                throw new \InvalidArgumentException(sprintf('Expected instances of %s.', Outlet::class));
            }

            $out[] = $outlet;
        }

        return $out;
    }
}
