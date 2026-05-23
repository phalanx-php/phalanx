<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Ui;

use Phalanx\Theatron\Context\RenderEnvironment;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Styling\BBCode;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\DividerElement;
use Phalanx\Theatron\Tdom\Element\GridElement;
use Phalanx\Theatron\Tdom\Element\InputElement;
use Phalanx\Theatron\Tdom\Element\MountElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\ProgressElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\ScrollElement;
use Phalanx\Theatron\Tdom\Element\SpinnerElement;
use Phalanx\Theatron\Tdom\Element\StatusLineElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;

function text(string|Line $content, ?Style $style = null): TextElement
{
    if (is_string($content) && ($theme = RenderEnvironment::theme()) !== null && str_contains($content, '[')) {
        $content = BBCode::parse($content, $theme);
    }

    return new TextElement($content, $style);
}

function panel(string|Line $title, Renderable $child, ?Style $style = null): PanelElement
{
    if (is_string($title) && ($theme = RenderEnvironment::theme()) !== null && str_contains($title, '[')) {
        $title = BBCode::parse($title, $theme);
    }

    return new PanelElement($title, $child, $style);
}

function column(Renderable ...$children): ColumnElement
{
    return new ColumnElement(array_values($children));
}

function row(Renderable ...$children): RowElement
{
    return new RowElement(array_values($children));
}

/** @param list<Size> $columns */
function grid(array $columns, Renderable ...$children): GridElement
{
    return new GridElement($columns, array_values($children));
}

function scrollable(string $content, ?int $maxLines = null, ?Style $style = null): ScrollElement
{
    return new ScrollElement($content, $maxLines, $style);
}

function input(
    string $value = '',
    string|Line $prompt = '> ',
    int $cursor = 0,
    ?Style $style = null,
): InputElement {
    if (is_string($prompt) && ($theme = RenderEnvironment::theme()) !== null && str_contains($prompt, '[')) {
        $prompt = BBCode::parse($prompt, $theme);
    }

    return new InputElement($value, $prompt, $cursor, $style);
}

function statusLine(Renderable ...$sections): StatusLineElement
{
    return new StatusLineElement(array_values($sections));
}

function spinner(string|Line|null $label = null, int $frame = 0, ?Style $style = null): SpinnerElement
{
    if (is_string($label) && ($theme = RenderEnvironment::theme()) !== null && str_contains($label, '[')) {
        $label = BBCode::parse($label, $theme);
    }

    return new SpinnerElement(
        $label,
        $frame,
        $style,
    );
}

function divider(?Style $style = null): DividerElement
{
    return new DividerElement($style);
}

function progress(float $value, string|Line|null $label = null, ?Style $style = null): ProgressElement
{
    if (is_string($label) && ($theme = RenderEnvironment::theme()) !== null && str_contains($label, '[')) {
        $label = BBCode::parse($label, $theme);
    }

    return new ProgressElement(
        $value,
        $label,
        $style,
    );
}

/**
 * @template T of Component
 * @param class-string<T> $component
 */
function mount(string $component, mixed ...$props): MountElement
{
    return new MountElement($component, $props);
}
