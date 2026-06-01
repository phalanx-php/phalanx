<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Kit;

use Closure;
use Phalanx\Theatron\Tui\Drawing\Rect;
use Phalanx\Theatron\Tui\Drawing\Region;
use RuntimeException;

final class ScreenLayoutSlot
{
    private ?Region $region = null;

    /** @param Closure(int, int): Rect $rectFactory */
    public function __construct(
        private(set) string $name,
        private Closure $rectFactory,
    ) {
    }

    public function region(): Region
    {
        if ($this->region === null) {
            throw new RuntimeException(sprintf('Slot "%s" has not been attached to a Stage yet.', $this->name));
        }

        return $this->region;
    }

    public function rect(int $width, int $height): Rect
    {
        return ($this->rectFactory)($width, $height);
    }

    /** @internal Called by ScreenLayout::attach() */
    public function attachRegion(Region $region): void
    {
        $this->region = $region;
    }

    public function resize(int $width, int $height): void
    {
        $this->region()->resize($this->rect($width, $height));
    }
}
