<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Boundaries;

use Phalanx\Tui\Collab\Events\Event;
use Phalanx\Tui\Collab\Participants\Reactor;
use Phalanx\Tui\Collab\WorkContext;

final class OutletReactor implements Reactor
{
    /** @var list<Outlet> */
    private array $outlets;

    /** @param iterable<Outlet> $outlets */
    public function __construct(iterable $outlets)
    {
        $this->outlets = self::outlets($outlets);
    }

    public function __invoke(Event $event, WorkContext $ctx): void
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
