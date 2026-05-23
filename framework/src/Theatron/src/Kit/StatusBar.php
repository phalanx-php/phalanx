<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Kit;

use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Style;
use function Phalanx\Theatron\Ui\text;

final class StatusBar
{
    /** @var list<StatusBarSection> */
    private array $sections = [];

    private Color $background;
    private Color $defaultTextColor;

    public function __construct(?Color $background = null, ?Theme $theme = null)
    {
        $this->background = $background ?? ($theme !== null ? $theme->bg : Color::indexed(236));
        $this->defaultTextColor = $theme !== null
            ? ($theme->bright->foreground ?? Color::brightWhite())
            : Color::brightWhite();
    }

    public static function new(?Color $background = null, ?Theme $theme = null): self
    {
        return new self($background, $theme);
    }

    public function section(string $text, ?Color $color = null, bool $fill = false): self
    {
        $this->sections[] = new StatusBarSection($text, $color, $fill);

        return $this;
    }

    public function left(string $text, ?Color $color = null): self
    {
        return $this->section($text, $color, fill: true);
    }

    public function right(string $text, ?Color $color = null): self
    {
        return $this->section($text, $color, fill: false);
    }

    public function render(): StatusLineElement
    {
        $elements = [];

        foreach ($this->sections as $section) {
            $elements[] = text(
                $section->text,
                style: Style::of(
                    size: $section->fill ? Size::fill() : null,
                    color: $section->color ?? $this->defaultTextColor,
                    background: $this->background,
                ),
            );
        }

        return new StatusLineElement(
            sections: $elements,
            style: Style::of(background: $this->background),
        );
    }
}
