<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tdom;

use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\DividerElement;
use Phalanx\Theatron\Tdom\Element\GridElement;
use Phalanx\Theatron\Tdom\Element\InputElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\ProgressElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\ScrollElement;
use Phalanx\Theatron\Tdom\Element\SpinnerElement;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Text\Line;

final class Ui
{
    public function text(string|Line $content, ?Style $style = null): TextElement
    {
        return new TextElement($content, $style);
    }

    public function panel(string $title, Renderable $child, ?Style $style = null): PanelElement
    {
        return new PanelElement($title, $child, $style);
    }

    public function column(Renderable ...$children): ColumnElement
    {
        return new ColumnElement(array_values($children));
    }

    public function row(Renderable ...$children): RowElement
    {
        return new RowElement(array_values($children));
    }

    /** @param list<Size> $columns */
    public function grid(array $columns, Renderable ...$children): GridElement
    {
        return new GridElement($columns, array_values($children));
    }

    public function scrollable(string $content, ?int $maxLines = null, ?Style $style = null): ScrollElement
    {
        return new ScrollElement($content, $maxLines, $style);
    }

    public function input(
        string $value = '',
        string $prompt = '> ',
        int $cursor = 0,
        ?Style $style = null,
    ): InputElement {
        return new InputElement($value, $prompt, $cursor, $style);
    }

    public function statusLine(Renderable ...$sections): StatusLineElement
    {
        return new StatusLineElement(array_values($sections));
    }

    public function spinner(?string $label = null, int $frame = 0, ?Style $style = null): SpinnerElement
    {
        return new SpinnerElement($label, $frame, $style);
    }

    public function divider(?Style $style = null): DividerElement
    {
        return new DividerElement($style);
    }

    public function progress(float $value, ?string $label = null, ?Style $style = null): ProgressElement
    {
        return new ProgressElement($value, $label, $style);
    }
}
