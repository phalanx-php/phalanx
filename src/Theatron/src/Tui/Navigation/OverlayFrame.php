<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Navigation;

use Phalanx\Theatron\Tui\Drawing\Rect;

final readonly class OverlayFrame
{
    private function __construct(
        private(set) Rect $rect,
        private(set) bool $backdrop,
    ) {
    }

    public static function fullscreen(Rect $bounds, bool $backdrop = false): self
    {
        return new self(Rect::of(0, 0, $bounds->width, $bounds->height), $backdrop);
    }

    public static function rightPanel(Rect $bounds, bool $backdrop = true): self
    {
        if ($bounds->width <= 0 || $bounds->height <= 0) {
            return new self(Rect::sized(0, 0), $backdrop);
        }

        $minimum = min(24, $bounds->width);
        $target = max($minimum, (int) floor($bounds->width * 0.45));
        $width = min($bounds->width, 48, $target);

        return new self(
            Rect::of($bounds->width - $width, 0, $width, $bounds->height),
            $backdrop,
        );
    }

    public static function centered(Rect $bounds, int $width, int $height, bool $backdrop = true): self
    {
        if ($bounds->width <= 0 || $bounds->height <= 0) {
            return new self(Rect::sized(0, 0), $backdrop);
        }

        $width = min($bounds->width, max(1, $width));
        $height = min($bounds->height, max(1, $height));

        return new self(
            Rect::of(
                max(0, intdiv($bounds->width - $width, 2)),
                max(0, intdiv($bounds->height - $height, 2)),
                $width,
                $height,
            ),
            $backdrop,
        );
    }
}
