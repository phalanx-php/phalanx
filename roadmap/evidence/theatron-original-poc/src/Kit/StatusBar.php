<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Kit;

use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

final class StatusBar
{
    private static ?Color $defaultBackground = null;

    /** @var list<StatusBarSection> */
    private array $sections = [];

    private Color $background;

    public function __construct(?Color $background = null)
    {
        $this->background = $background ?? self::defaultBackground();
    }

    public static function new(?Color $background = null): self
    {
        return new self($background);
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

    public function render(Ui $ui): StatusLineElement
    {
        $elements = [];

        foreach ($this->sections as $section) {
            $elements[] = $ui->text(
                $section->text,
                style: Style::of(
                    size: $section->fill ? Size::fill() : null,
                    color: $section->color ?? Color::brightWhite(),
                    background: $this->background,
                ),
            );
        }

        return new StatusLineElement(
            sections: $elements,
            style: Style::of(background: $this->background),
        );
    }

    private static function defaultBackground(): Color
    {
        return self::$defaultBackground ??= Color::indexed(236);
    }
}
