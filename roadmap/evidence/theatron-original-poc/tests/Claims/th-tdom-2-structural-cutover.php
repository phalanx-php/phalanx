#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Modifier;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Element;
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
use Phalanx\Theatron\Tdom\ElementType;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

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

function paintToBuffer(Renderable $node, int $w, int $h): Buffer
{
    $buffer = Buffer::empty($w, $h);
    $ctx = new PaintContext(Rect::of(0, 0, $w, $h), $buffer);
    Painter::paint($node, $ctx);

    return $buffer;
}

// ============================================================================
// Group 1: No Widget references in src/
// ============================================================================

$srcDir = __DIR__ . '/../../src';
$filesScanned = 0;
$widgetRefs = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $filesScanned++;
    $content = file_get_contents($file->getPathname());
    $relative = str_replace($srcDir . '/', '', $file->getPathname());

    if (preg_match('/\bWidget\b/', $content)) {
        $widgetRefs[] = $relative;
    }
}

assertTrue($filesScanned >= 80, "Source scan covered {$filesScanned} files (need 80+)");
assertTrue($widgetRefs === [], 'No Widget references in src/ — found in: ' . implode(', ', $widgetRefs));

// ============================================================================
// Group 2: Renderable is sole tree-node type
// ============================================================================

$renderableRef = new ReflectionClass(Renderable::class);
assertTrue($renderableRef->isInterface(), 'Renderable is an interface');
assertTrue($renderableRef->hasProperty('style'), 'Renderable has style property');

$elementRef = new ReflectionClass(Element::class);
assertTrue($elementRef->isInterface(), 'Element is an interface');
assertTrue(in_array(Renderable::class, $elementRef->getInterfaceNames(), true), 'Element extends Renderable');
assertTrue($elementRef->hasProperty('type'), 'Element has type property');

$elementClasses = [
    TextElement::class, PanelElement::class, ColumnElement::class, RowElement::class,
    GridElement::class, InputElement::class, ScrollElement::class, SpinnerElement::class,
    DividerElement::class, ProgressElement::class, StatusLineElement::class,
];

foreach ($elementClasses as $class) {
    $ref = new ReflectionClass($class);
    $short = $ref->getShortName();
    assertTrue($ref->implementsInterface(Element::class), "{$short} implements Element");
    assertTrue($ref->implementsInterface(Renderable::class), "{$short} implements Renderable");
}

$painterRef = new ReflectionMethod(Painter::class, 'paint');
$firstParam = $painterRef->getParameters()[0];
assertTrue(
    $firstParam->getType() instanceof ReflectionNamedType && $firstParam->getType()->getName() === Renderable::class,
    'Painter::paint() first param typed as Renderable',
);

$panelChildRef = new ReflectionProperty(PanelElement::class, 'child');
$panelChildType = $panelChildRef->getType();
assertTrue(
    $panelChildType instanceof ReflectionNamedType && $panelChildType->getName() === Renderable::class,
    'PanelElement::$child typed as Renderable',
);

foreach ([ColumnElement::class, RowElement::class] as $containerClass) {
    $childrenRef = new ReflectionProperty($containerClass, 'children');
    $short = new ReflectionClass($containerClass)->getShortName();
    assertTrue($childrenRef->getType()->getName() === 'array', "{$short}::\$children is array");
}

// ============================================================================
// Group 3: Element painting — InputPainter
// ============================================================================

$ui = new Ui();
$input = $ui->input(value: 'hello', prompt: '> ', cursor: 3);
$buf = paintToBuffer($input, 40, 1);
assertString($buf, 0, 0, '> ', 'Input prompt renders');
assertString($buf, 2, 0, 'hello', 'Input value renders');

$cursorCell = $buf->get(5, 0);
assertTrue(
    $cursorCell->style->hasModifier(Modifier::Reverse),
    'Input cursor has Reverse modifier at position 5',
);
assertTrue($cursorCell->char === 'l', 'Cursor is on the correct character');

$longInput = $ui->input(value: str_repeat('x', 50), prompt: '> ', cursor: 0);
$bufLong = paintToBuffer($longInput, 20, 1);
assertCell($bufLong, 19, 0, 'x', 'Input truncates to area width');

// ============================================================================
// Group 3: Element painting — ScrollPainter
// ============================================================================

$scroll = $ui->scrollable("line1\nline2\nline3\nline4\nline5", maxLines: 3);
$buf = paintToBuffer($scroll, 20, 5);
assertString($buf, 0, 0, 'line3', 'Scroll line 1 (auto-scroll shows last 3)');
assertString($buf, 0, 1, 'line4', 'Scroll line 2 (auto-scroll shows last 3)');
assertString($buf, 0, 2, 'line5', 'Scroll line 3 (auto-scroll shows last 3)');
$cell4 = $buf->get(0, 3);
assertTrue($cell4->char === ' ', 'Scroll line 4 not rendered (maxLines=3)');

$scrollNoLimit = $ui->scrollable("a\nb\nc", maxLines: null);
$bufNl = paintToBuffer($scrollNoLimit, 10, 2);
assertString($bufNl, 0, 0, 'b', 'Scroll no-limit line 1 (auto-scroll)');
assertString($bufNl, 0, 1, 'c', 'Scroll no-limit line 2 (auto-scroll)');

// ============================================================================
// Group 3: Element painting — StatusLinePainter
// ============================================================================

$statusFill = $ui->text('LEFT', Style::of(size: Size::fill()));
$statusFixed = $ui->text('RIGHT', Style::of(size: Size::fixed(1)));
$status = new StatusLineElement(
    sections: [$statusFill, $statusFixed],
    style: Style::of(background: Color::named('black')),
);
$buf = paintToBuffer($status, 40, 1);
assertString($buf, 0, 0, 'LEFT', 'StatusLine fill section text');

$midCell = $buf->get(20, 0);
assertTrue($midCell->style->background !== null, 'StatusLine background fill covers middle');

// ============================================================================
// Group 3: Element painting — SpinnerPainter with label
// ============================================================================

$spinner = $ui->spinner(label: 'Working...', frame: 5);
$buf = paintToBuffer($spinner, 30, 1);

$spinnerChar = $buf->get(0, 0)->char;
assertTrue(mb_strlen($spinnerChar) === 1 && ord($spinnerChar[0]) > 127, 'Spinner renders braille dot');
assertString($buf, 2, 0, 'Working...', 'Spinner label at x=2');

$spinnerNoLabel = $ui->spinner(frame: 0);
$bufNl = paintToBuffer($spinnerNoLabel, 10, 1);
$nlChar = $bufNl->get(0, 0)->char;
assertTrue(ord($nlChar[0]) > 127, 'Spinner without label renders braille');
assertTrue($bufNl->get(2, 0)->char === ' ', 'No label means empty after spinner');

// ============================================================================
// Group 3: Element painting — ProgressPainter with label
// ============================================================================

$progress = $ui->progress(0.75, label: 'Upload');
$buf = paintToBuffer($progress, 40, 1);
assertString($buf, 0, 0, 'Upload', 'Progress label renders');

$pctFound = false;

for ($x = 30; $x < 40; $x++) {
    if ($buf->get($x, 0)->char === '%') {
        $pctFound = true;
        break;
    }
}

assertTrue($pctFound, 'Progress percentage symbol present');

$filledCount = 0;
$emptyCount = 0;

for ($x = 7; $x < 35; $x++) {
    $c = $buf->get($x, 0)->char;

    if ($c === '█') {
        $filledCount++;
    }

    if ($c === '░') {
        $emptyCount++;
    }
}

assertTrue($filledCount > 0, 'Progress has filled blocks');
assertTrue($emptyCount > 0, 'Progress has empty blocks');
assertTrue($filledCount > $emptyCount, 'Progress 75% has more filled than empty');

// ============================================================================
// Group 3: Element painting — Color/background on TextElement
// ============================================================================

$styledText = $ui->text('Olympus', Style::of(color: Color::cyan(), background: Color::named('black')));
$buf = paintToBuffer($styledText, 20, 1);
assertString($buf, 0, 0, 'Olympus', 'Styled text content');

$styledCell = $buf->get(0, 0);
assertTrue($styledCell->style->foreground !== null, 'Text fg color applied');
assertTrue($styledCell->style->background !== null, 'Text bg color applied');

// ============================================================================
// Group 3: Element painting — DividerPainter
// ============================================================================

$divider = $ui->divider();
$buf = paintToBuffer($divider, 10, 1);
assertCell($buf, 0, 0, '─', 'Divider renders dash at x=0');
assertCell($buf, 9, 0, '─', 'Divider renders dash at x=9');

// ============================================================================
// Group 4: Nested composition — 3+ levels deep
// ============================================================================

$nested = $ui->panel(
    'Olympus',
    $ui->column(
        $ui->row(
            $ui->text('Zeus', Style::of(size: Size::fill())),
            $ui->text('Apollo', Style::of(size: Size::fill())),
        ),
        $ui->panel(
            'Inner',
            $ui->grid(
                [Size::fill(), Size::fill()],
                $ui->text('A'),
                $ui->text('B'),
                $ui->text('C'),
                $ui->text('D'),
            ),
            style: Style::of(border: Border::Single),
        ),
    ),
    style: Style::of(border: Border::Rounded),
);

$buf = paintToBuffer($nested, 60, 20);

assertCell($buf, 0, 0, '╭', 'Outer panel top-left corner (Rounded)');
assertCell($buf, 59, 0, '╮', 'Outer panel top-right corner');
assertCell($buf, 0, 19, '╰', 'Outer panel bottom-left corner');
assertCell($buf, 59, 19, '╯', 'Outer panel bottom-right corner');

assertString($buf, 2, 0, 'Olympus', 'Outer panel title');

$innerFound = false;

for ($y = 2; $y < 19; $y++) {
    $c = $buf->get(1, $y)->char;

    if ($c === '┌') {
        $innerFound = true;
        break;
    }
}

assertTrue($innerFound, 'Inner panel border (Single) found within outer panel');

$zeusFound = false;

for ($y = 1; $y < 5; $y++) {
    $seq = '';

    for ($x = 1; $x < 20; $x++) {
        $seq .= $buf->get($x, $y)->char;
    }

    if (str_contains($seq, 'Zeus')) {
        $zeusFound = true;
        break;
    }
}

assertTrue($zeusFound, 'Zeus text found in nested Row');

// ============================================================================
// Group 4: Element interface compliance — all 11 types
// ============================================================================

$typeChecks = [
    [ElementType::Text,       $ui->text('x')],
    [ElementType::Panel,      $ui->panel('t', $ui->text('c'), style: Style::of(border: Border::Single))],
    [ElementType::Column,     $ui->column($ui->text('a'))],
    [ElementType::Row,        $ui->row($ui->text('a'))],
    [ElementType::Grid,       $ui->grid([Size::fill()], $ui->text('a'))],
    [ElementType::Input,      $ui->input()],
    [ElementType::Scroll,     $ui->scrollable('x')],
    [ElementType::Spinner,    $ui->spinner()],
    [ElementType::Divider,    $ui->divider()],
    [ElementType::Progress,   $ui->progress(0.5)],
    [ElementType::StatusLine, $ui->statusLine($ui->text('a'))],
];

foreach ($typeChecks as [$expectedType, $element]) {
    assertTrue($element instanceof Element, "{$expectedType->name} is Element");
    assertTrue($element->type === $expectedType, "{$expectedType->name} type matches");
}

$unstyledText = $ui->text('plain');
assertTrue($unstyledText->style === null, 'Unstyled element has null style');

$styledDiv = $ui->divider(Style::of(color: Color::red()));
assertTrue($styledDiv->style !== null, 'Styled element has non-null style');

// ============================================================================
// Result
// ============================================================================

echo "TH-TDOM-2 structural cutover claim passed ({$assertions} assertions)\n";
