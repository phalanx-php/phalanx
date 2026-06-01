<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Drawing;

use Phalanx\Theatron\Tui\Drawing\Buffer;

final class Compositor
{
    public bool $isDirty {
        get {
    foreach ($this->regions as $region) {
        if ($region->isDirty) {
            return true;
        }
    }

            return false;
        }
    }

    /** @var array<string, Region> */
    private array $regions = [];

    /** @var list<string>|null */
    private ?array $zOrder = null;

    public function register(Region $region): void
    {
        $this->regions[$region->name] = $region;
        $this->zOrder = null;
    }

    public function remove(string $name): void
    {
        unset($this->regions[$name]);
        $this->zOrder = null;
    }

    public function get(string $name): ?Region
    {
        return $this->regions[$name] ?? null;
    }

    public function compose(Buffer $target, float $now): void
    {
        $order = $this->resolveZOrder();

        foreach ($order as $name) {
            $region = $this->regions[$name];

            if (!$region->isDueForTick($now)) {
                continue;
            }

            $buf = $region->buffer();
            $x = $region->area->x;
            $y = $region->area->y;

            if ($region->zIndex > 0) {
                $target->blitOpaque($buf, $x, $y);
            } else {
                $target->blitFull($buf, $x, $y);
            }

            $region->clean();
        }
    }

    public function composeAll(Buffer $target): void
    {
        foreach ($this->resolveZOrder() as $name) {
            $region = $this->regions[$name];
            $buf = $region->buffer();
            $x = $region->area->x;
            $y = $region->area->y;

            if ($region->zIndex > 0) {
                $target->blitOpaque($buf, $x, $y);
            } else {
                $target->blitFull($buf, $x, $y);
            }

            $region->clean();
        }
    }

    /** @return list<string> region names sorted by z-index ascending */
    private function resolveZOrder(): array
    {
        if ($this->zOrder !== null) {
            return $this->zOrder;
        }

        $entries = [];

        foreach ($this->regions as $name => $region) {
            $entries[] = [$name, $region->zIndex];
        }

        usort($entries, static fn(array $a, array $b): int => $a[1] <=> $b[1]);

        $ordered = [];
        foreach ($entries as $entry) {
            $ordered[] = $entry[0];
        }

        $this->zOrder = $ordered;

        return $this->zOrder;
    }
}
