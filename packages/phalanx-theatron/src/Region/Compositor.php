<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Region;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;

final class Compositor
{
    /** @var array<string, Region> */
    private array $regions = [];

    /** @var string[]|null cached z-order, invalidated on add/remove */
    private ?array $zOrder = null;

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

            $target->blit(
                $region->buffer(),
                Rect::sized($region->area->width, $region->area->height),
                $region->area->x,
                $region->area->y,
            );

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

        $this->zOrder = array_map(static fn(array $e): string => $e[0], $entries);

        return $this->zOrder;
    }
}
