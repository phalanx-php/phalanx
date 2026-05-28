<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Store\StoreWriter;

final class ComponentTreeProjector
{
    /** @var list<array{MountedComponent, string, int}> */
    private array $mounted = [];

    public function __construct(
        private readonly StoreWriter $writer,
    ) {
    }

    public function register(MountedComponent $component, string $name, int $depth = 0): void
    {
        $this->mounted[] = [$component, $name, $depth];
    }

    public function unregister(MountedComponent $component): void
    {
        $this->mounted = array_values(array_filter(
            $this->mounted,
            static fn(array $entry): bool => $entry[0] !== $component,
        ));
    }

    public function project(): void
    {
        $nodes = [];

        foreach ($this->mounted as [$component, $name, $depth]) {
            $nodes[] = new ComponentTreeNode(
                name: $name,
                class: $component->componentClass(),
                depth: $depth,
                signalCount: $component->state->signalCount,
                subscriptionCount: $component->state->subscriptionCount,
            );
        }

        $this->writer->update(
            ComponentTreeSlice::class,
            static fn(ComponentTreeSlice $s): ComponentTreeSlice => $s->withNodes($nodes),
        );
    }
}
