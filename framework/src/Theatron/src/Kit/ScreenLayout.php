<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Kit;

use Closure;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Rendering\Region;
use Phalanx\Theatron\Stage\Stage;
use RuntimeException;

final class ScreenLayout
{
    /** @var array<string, ScreenLayoutSlot> */
    private(set) array $slots = [];

    public static function mainWithStatusBar(): self
    {
        return new self()
            ->slot('main', static fn(int $w, int $h): Rect => Rect::of(0, 0, $w, max(1, $h - 1)))
            ->slot('status', static fn(int $w, int $h): Rect => Rect::of(0, max(0, $h - 1), $w, 1));
    }

    public static function mainWithDevtoolsAndStatusBar(int $devtoolsHeight = 6): self
    {
        return new self()
            ->slot('main', static fn(int $w, int $h): Rect => Rect::of(
                0,
                0,
                $w,
                max(1, $h - 1 - $devtoolsHeight),
            ))
            ->slot('devtools', static fn(int $w, int $h): Rect => Rect::of(
                0,
                max(0, $h - 1 - $devtoolsHeight),
                $w,
                min($devtoolsHeight, max(1, $h - 1)),
            ))
            ->slot('status', static fn(int $w, int $h): Rect => Rect::of(0, max(0, $h - 1), $w, 1));
    }

    public function slot(string $name, Closure $rectFactory): self
    {
        $this->slots[$name] = new ScreenLayoutSlot($name, $rectFactory);

        return $this;
    }

    public function attach(Stage $stage): self
    {
        $w = $stage->width();
        $h = $stage->height();

        foreach ($this->slots as $slot) {
            $slot->attachRegion($stage->region($slot->name, $slot->rect($w, $h)));
        }

        $slots = $this->slots;

        $stage->onResize(static function (int $width, int $height) use ($slots): void {
            foreach ($slots as $slot) {
                $slot->resize($width, $height);
            }
        });

        return $this;
    }

    public function region(string $name): Region
    {
        if (!isset($this->slots[$name])) {
            throw new RuntimeException(sprintf('Unknown layout slot: %s', $name));
        }

        return $this->slots[$name]->region();
    }
}
