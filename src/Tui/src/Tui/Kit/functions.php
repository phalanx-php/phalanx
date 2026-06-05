<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Kit;

use Phalanx\Tui\Tui\Core\Component;
use Phalanx\Tui\Tui\Core\RenderEnvironment;
use Phalanx\Tui\Tui\Styles\BBCode;
use Phalanx\Tui\Tui\Styles\Line;
use Phalanx\Tui\Tui\Styles\Size;
use Phalanx\Tui\Tui\Tdom\Element\ColumnElement;
use Phalanx\Tui\Tui\Tdom\Element\DividerElement;
use Phalanx\Tui\Tui\Tdom\Element\GridElement;
use Phalanx\Tui\Tui\Tdom\Element\InputElement;
use Phalanx\Tui\Tui\Tdom\Element\MountElement;
use Phalanx\Tui\Tui\Tdom\Element\PanelElement;
use Phalanx\Tui\Tui\Tdom\Element\ProgressElement;
use Phalanx\Tui\Tui\Tdom\Element\RowElement;
use Phalanx\Tui\Tui\Tdom\Element\ScrollElement;
use Phalanx\Tui\Tui\Tdom\Element\SpinnerElement;
use Phalanx\Tui\Tui\Tdom\Element\StatusLineElement;
use Phalanx\Tui\Tui\Tdom\Element\TextElement;
use Phalanx\Tui\Tui\Tdom\Renderable;
use Phalanx\Tui\Tui\Tdom\Style;

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
    ?int $selectionStart = null,
    ?int $selectionEnd = null,
): InputElement {
    if (is_string($prompt) && ($theme = RenderEnvironment::theme()) !== null && str_contains($prompt, '[')) {
        $prompt = BBCode::parse($prompt, $theme);
    }

    return new InputElement($value, $prompt, $cursor, $style, $selectionStart, $selectionEnd);
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
