#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\SizeResolver;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Align;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Element\GridElement;
use Phalanx\Theatron\Tdom\Element\PanelElement;
use Phalanx\Theatron\Tdom\Element\RowElement;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\Padding;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\SizeKind;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Style\Style as AnsiStyle;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;

    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assertCell(Buffer $buffer, int $x, int $y, string $char, string $message): void
{
    $cell = $buffer->get($x, $y);
    assertTrue($cell->char === $char, "{$message} (expected '{$char}', got '{$cell->char}' at {$x},{$y})");
}

function assertString(Buffer $buffer, int $x, int $y, string $text, string $message): void
{
    for ($i = 0; $i < mb_strlen($text); $i++) {
        $char = mb_substr($text, $i, 1);
        assertCell($buffer, $x + $i, $y, $char, "{$message} (char {$i})");
    }
}

// --- Foundation types ---

$size = Size::fixed(10);
assertTrue($size->kind === SizeKind::Fixed, 'Size::fixed() creates Fixed kind');
assertTrue($size->value === 10, 'Size::fixed() carries value');

$size = Size::fr(2);
assertTrue($size->kind === SizeKind::Fractional, 'Size::fr() creates Fractional kind');
assertTrue($size->value === 2, 'Size::fr() carries weight');

$size = Size::between(5, 20);
assertTrue($size->kind === SizeKind::Between, 'Size::between() creates Between kind');
assertTrue($size->value === 5 && $size->max === 20, 'Size::between() carries min and max');

$style = Style::of(
    size: Size::fill(),
    align: Align::Center,
    border: Border::Rounded,
    padding: Padding::horizontal(1),
    color: Color::cyan(),
    background: Color::named('black'),
);

assertTrue($style->size->kind === SizeKind::Fill, 'Style::of() threads size');
assertTrue($style->align === Align::Center, 'Style::of() threads align');
assertTrue($style->border === Border::Rounded, 'Style::of() threads border');
assertTrue($style->padding->left === 1 && $style->padding->right === 1, 'Style::of() threads padding');
assertTrue($style->color !== null, 'Style::of() threads color');
assertTrue($style->background !== null, 'Style::of() threads background');

$chars = Border::Rounded->chars();
assertTrue($chars[0] === '╭' && $chars[1] === '╮', 'Border::Rounded has correct corner chars');
assertTrue($chars[4] === '─' && $chars[5] === '│', 'Border::Rounded has correct edge chars');

// --- Element types ---

$ui = new Ui();

$text = $ui->text('Zeus reigns');
assertTrue($text instanceof TextElement, 'Ui::text() creates TextElement');
assertTrue($text->type === ElementType::Text, 'TextElement has Text type');
assertTrue($text->content === 'Zeus reigns', 'TextElement carries content');
assertTrue($text->style === null, 'TextElement default style is null');
assertTrue($text instanceof Renderable, 'TextElement implements Renderable');

$styledText = $ui->text('Apollo', style: Style::of(color: Color::named('yellow')));
assertTrue($styledText->style !== null, 'TextElement accepts style');

$panel = $ui->panel('Olympus', $text, style: Style::of(border: Border::Rounded));
assertTrue($panel instanceof PanelElement, 'Ui::panel() creates PanelElement');
assertTrue($panel->type === ElementType::Panel, 'PanelElement has Panel type');
assertTrue($panel->title === 'Olympus', 'PanelElement carries title');
assertTrue($panel->child === $text, 'PanelElement carries child');

$col = $ui->column($text, $panel);
assertTrue($col instanceof ColumnElement, 'Ui::column() creates ColumnElement');
assertTrue(count($col->children) === 2, 'ColumnElement carries children');

$row = $ui->row($text, $panel);
assertTrue($row instanceof RowElement, 'Ui::row() creates RowElement');

$grid = $ui->grid([Size::fixed(10), Size::fr(1)], $text, $panel);
assertTrue($grid instanceof GridElement, 'Ui::grid() creates GridElement');
assertTrue(count($grid->columns) === 2, 'GridElement carries column definitions');

// --- SizeResolver ---

$resolved = SizeResolver::resolve(100, [Size::fixed(30), Size::fill()]);
assertTrue($resolved[0] === 30, 'SizeResolver: fixed(30) allocates 30');
assertTrue($resolved[1] === 70, 'SizeResolver: fill() takes remainder');

$resolved = SizeResolver::resolve(100, [Size::fr(1), Size::fr(2)]);
assertTrue($resolved[0] === 33, 'SizeResolver: fr(1) of fr(1)+fr(2) = 33');
assertTrue($resolved[1] === 67, 'SizeResolver: fr(2) of fr(1)+fr(2) = 67');

$resolved = SizeResolver::resolve(100, [Size::percent(25), Size::fill()]);
assertTrue($resolved[0] === 25, 'SizeResolver: percent(25) allocates 25');
assertTrue($resolved[1] === 75, 'SizeResolver: fill() takes remaining after percent');

$resolved = SizeResolver::resolve(80, [Size::fixed(20), Size::fr(1), Size::fr(3)]);
assertTrue($resolved[0] === 20, 'SizeResolver: mixed fixed+fr — fixed gets 20');
assertTrue($resolved[1] === 15, 'SizeResolver: mixed fixed+fr — fr(1) gets 15');
assertTrue($resolved[2] === 45, 'SizeResolver: mixed fixed+fr — fr(3) gets 45');

$rects = SizeResolver::vertical(Rect::sized(80, 24), [Size::fixed(1), Size::fill(), Size::fixed(3)]);
assertTrue(count($rects) === 3, 'SizeResolver::vertical returns rect per size');
assertTrue($rects[0]->y === 0 && $rects[0]->height === 1, 'vertical: first rect is 1 row');
assertTrue($rects[1]->y === 1 && $rects[1]->height === 20, 'vertical: middle rect fills remainder');
assertTrue($rects[2]->y === 21 && $rects[2]->height === 3, 'vertical: last rect is 3 rows');
assertTrue($rects[0]->width === 80 && $rects[1]->width === 80, 'vertical: all rects span full width');

$rects = SizeResolver::horizontal(Rect::sized(80, 24), [Size::fixed(20), Size::fill()]);
assertTrue($rects[0]->x === 0 && $rects[0]->width === 20, 'horizontal: first rect is 20 cols');
assertTrue($rects[1]->x === 20 && $rects[1]->width === 60, 'horizontal: second fills remainder');
assertTrue($rects[0]->height === 24 && $rects[1]->height === 24, 'horizontal: all rects span full height');

// --- SizeResolver edge cases ---

$resolved = SizeResolver::resolve(2, [Size::between(3, 10)]);
assertTrue($resolved[0] === 2, 'SizeResolver: between(3,10) in 2-wide space clamps to available');
assertTrue(array_sum($resolved) <= 2, 'SizeResolver: between never exceeds total');

$resolved = SizeResolver::resolve(100, [Size::between(20, 50), Size::fill()]);
assertTrue($resolved[0] === 20, 'SizeResolver: between(20,50) gets min');
assertTrue($resolved[1] === 80, 'SizeResolver: fill() takes remainder after between');

$resolved = SizeResolver::resolve(100, [Size::percent(50), Size::percent(50), Size::percent(50)]);
assertTrue(array_sum($resolved) <= 100, 'SizeResolver: oversubscribed percent never exceeds total');
assertTrue($resolved[0] === 50, 'SizeResolver: first percent(50) gets 50');
assertTrue($resolved[1] === 50, 'SizeResolver: second percent(50) gets 50');
assertTrue($resolved[2] === 0, 'SizeResolver: third percent(50) gets 0 (no remaining)');

$resolved = SizeResolver::resolve(0, [Size::fixed(10), Size::fill()]);
assertTrue($resolved[0] === 0, 'SizeResolver: zero total gives zero to fixed');
assertTrue($resolved[1] === 0, 'SizeResolver: zero total gives zero to fill');

$resolved = SizeResolver::resolve(10, [Size::fixed(20)]);
assertTrue($resolved[0] === 10, 'SizeResolver: fixed larger than total clamps to total');

// --- TextPainter ---

$buffer = Buffer::empty(40, 5);
Painter::paint($ui->text('Leonidas'), new PaintContext(Rect::sized(40, 5), $buffer));
assertString($buffer, 0, 0, 'Leonidas', 'TextPainter renders plain string');

// --- TextPainter with Line ---

$buffer = Buffer::empty(40, 5);
$line = Line::from(Span::styled('Sparta', AnsiStyle::new()->bold()));
Painter::paint($ui->text($line), new PaintContext(Rect::sized(40, 5), $buffer));
assertString($buffer, 0, 0, 'Sparta', 'TextPainter renders Line with Spans');

// --- PanelPainter ---

$buffer = Buffer::empty(20, 5);
$tree = $ui->panel('Zeus', $ui->text('Thunder'), style: Style::of(border: Border::Single));
Painter::paint($tree, new PaintContext(Rect::sized(20, 5), $buffer));

assertCell($buffer, 0, 0, '┌', 'PanelPainter: top-left corner');
assertCell($buffer, 19, 0, '┐', 'PanelPainter: top-right corner');
assertCell($buffer, 0, 4, '└', 'PanelPainter: bottom-left corner');
assertCell($buffer, 19, 4, '┘', 'PanelPainter: bottom-right corner');
assertCell($buffer, 0, 2, '│', 'PanelPainter: left edge');
assertCell($buffer, 1, 0, ' ', 'PanelPainter: title padding before');
assertString($buffer, 2, 0, 'Zeus', 'PanelPainter: title text');
assertString($buffer, 1, 1, 'Thunder', 'PanelPainter: inner content');

// --- PanelPainter with Rounded border ---

$buffer = Buffer::empty(20, 5);
$tree = $ui->panel('Apollo', $ui->text('Light'), style: Style::of(border: Border::Rounded));
Painter::paint($tree, new PaintContext(Rect::sized(20, 5), $buffer));

assertCell($buffer, 0, 0, '╭', 'PanelPainter rounded: top-left corner');
assertCell($buffer, 19, 0, '╮', 'PanelPainter rounded: top-right corner');

// --- ColumnPainter ---

$buffer = Buffer::empty(40, 10);
$tree = $ui->column(
    $ui->text('Marathon', style: Style::of(size: Size::fixed(2))),
    $ui->text('Thermopylae', style: Style::of(size: Size::fill())),
    $ui->text('Salamis', style: Style::of(size: Size::fixed(1))),
);
Painter::paint($tree, new PaintContext(Rect::sized(40, 10), $buffer));

assertString($buffer, 0, 0, 'Marathon', 'ColumnPainter: first child at row 0');
assertString($buffer, 0, 2, 'Thermopylae', 'ColumnPainter: second child at row 2 (after fixed 2)');
assertString($buffer, 0, 9, 'Salamis', 'ColumnPainter: third child at last row');

// --- RowPainter ---

$buffer = Buffer::empty(40, 5);
$tree = $ui->row(
    $ui->text('Hoplite', style: Style::of(size: Size::fixed(10))),
    $ui->text('Phalanx', style: Style::of(size: Size::fill())),
);
Painter::paint($tree, new PaintContext(Rect::sized(40, 5), $buffer));

assertString($buffer, 0, 0, 'Hoplite', 'RowPainter: first child at col 0');
assertString($buffer, 10, 0, 'Phalanx', 'RowPainter: second child at col 10');

// --- GridPainter ---

$buffer = Buffer::empty(40, 4);
$tree = $ui->grid(
    [Size::fixed(20), Size::fixed(20)],
    $ui->text('Ares'),
    $ui->text('Poseidon'),
    $ui->text('Demeter'),
    $ui->text('Hephaestus'),
);
Painter::paint($tree, new PaintContext(Rect::sized(40, 4), $buffer));

assertString($buffer, 0, 0, 'Ares', 'GridPainter: cell (0,0)');
assertString($buffer, 20, 0, 'Poseidon', 'GridPainter: cell (1,0)');
assertString($buffer, 0, 2, 'Demeter', 'GridPainter: cell (0,1)');
assertString($buffer, 20, 2, 'Hephaestus', 'GridPainter: cell (1,1)');

// --- DividerPainter ---

$buffer = Buffer::empty(10, 1);
Painter::paint($ui->divider(), new PaintContext(Rect::sized(10, 1), $buffer));

assertCell($buffer, 0, 0, '─', 'DividerPainter: first char');
assertCell($buffer, 9, 0, '─', 'DividerPainter: last char');

// --- SpinnerPainter ---

$buffer = Buffer::empty(20, 1);
Painter::paint($ui->spinner(label: 'Loading', frame: 3), new PaintContext(Rect::sized(20, 1), $buffer));

assertCell($buffer, 0, 0, '⠸', 'SpinnerPainter: frame 3 = ⠸');
assertString($buffer, 2, 0, 'Loading', 'SpinnerPainter: label after spinner');

// --- ProgressPainter ---

$buffer = Buffer::empty(30, 1);
Painter::paint($ui->progress(0.5), new PaintContext(Rect::sized(30, 1), $buffer));

$pctCell = $buffer->get(27, 0);
assertTrue($pctCell->char === '5', 'ProgressPainter: percentage contains 50');

// --- Nested tree: panel with column of text ---

$buffer = Buffer::empty(30, 8);
$tree = $ui->panel('Agora', $ui->column(
    $ui->text('Pericles', style: Style::of(size: Size::fixed(1))),
    $ui->text('Socrates', style: Style::of(size: Size::fixed(1))),
    $ui->text('Aristotle', style: Style::of(size: Size::fill())),
), style: Style::of(border: Border::Rounded));
Painter::paint($tree, new PaintContext(Rect::sized(30, 8), $buffer));

assertCell($buffer, 0, 0, '╭', 'Nested: panel corner');
assertString($buffer, 2, 0, 'Agora', 'Nested: panel title');
assertString($buffer, 1, 1, 'Pericles', 'Nested: first child inside panel');
assertString($buffer, 1, 2, 'Socrates', 'Nested: second child inside panel');
assertString($buffer, 1, 3, 'Aristotle', 'Nested: third child inside panel');

// --- Padding ---

$buffer = Buffer::empty(20, 5);
$tree = $ui->text('Doru', style: Style::of(padding: Padding::of(top: 1, left: 2)));
Painter::paint($tree, new PaintContext(Rect::sized(20, 5), $buffer));

assertCell($buffer, 0, 0, ' ', 'Padding: origin is blank');
assertString($buffer, 2, 1, 'Doru', 'Padding: text offset by padding');

// --- Panel with padding (S6: padding inside border) ---

$buffer = Buffer::empty(24, 7);
$tree = $ui->panel('Olympia', $ui->text('Flame'), style: Style::of(
    border: Border::Single,
    padding: Padding::of(top: 1, left: 2),
));
Painter::paint($tree, new PaintContext(Rect::sized(24, 7), $buffer));

assertCell($buffer, 0, 0, '┌', 'Panel+padding: border at outer edge');
assertCell($buffer, 23, 0, '┐', 'Panel+padding: right border at outer edge');
assertString($buffer, 2, 0, 'Olympia', 'Panel+padding: title on border');
assertString($buffer, 3, 2, 'Flame', 'Panel+padding: content offset by border+padding');

// --- Zero-size area ---

$buffer = Buffer::empty(10, 10);
Painter::paint($ui->text('Invisible'), new PaintContext(Rect::of(0, 0, 0, 0), $buffer));
assertCell($buffer, 0, 0, ' ', 'Zero-size: text paints nothing');

Painter::paint($ui->divider(), new PaintContext(Rect::of(0, 0, 0, 1), $buffer));
assertCell($buffer, 0, 0, ' ', 'Zero-width: divider paints nothing');

// --- Style value assertions (N4) ---

$style = Style::of(
    size: Size::fixed(42),
    align: Align::End,
    color: Color::named('red'),
    background: Color::named('blue'),
);
assertTrue($style->size->kind === SizeKind::Fixed && $style->size->value === 42, 'Style::of() size value identity');
assertTrue($style->align === Align::End, 'Style::of() align value identity');
assertTrue($style->border === null, 'Style::of() omitted border is null');
assertTrue($style->padding === null, 'Style::of() omitted padding is null');

fwrite(STDOUT, "TH-TDOM-1 element tree claim passed ({$assertions} assertions)\n");
