<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Theatron

> Part of the [Phalanx](https://github.com/phalanx-php/phalanx-aegis) async PHP framework.

Async terminal UI framework with tick-based rendering, region composition, and buffer diffing. Build interactive TUI applications with widgets, styled text, layout constraints, and full keyboard/mouse input -- all driven by the ReactPHP event loop.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Surface](#surface)
- [Regions](#regions)
- [Layout](#layout)
- [Widgets](#widgets)
  - [Box](#box)
  - [Table](#table)
  - [ScrollableText](#scrollabletext)
  - [InputLine](#inputline)
  - [ProgressBar](#progressbar)
  - [Spinner](#spinner)
  - [StatusBar](#statusbar)
  - [Sparkline](#sparkline)
  - [CodeBlock](#codeblock)
  - [Accordion](#accordion)
  - [Divider](#divider)
- [Styling](#styling)
- [Input Handling](#input-handling)
- [Terminal Detection](#terminal-detection)
- [Custom Widgets](#custom-widgets)

## Installation

```bash
composer require phalanx/theatron
```

> [!NOTE]
> Requires PHP 8.4 or later.

## Quick Start

```php
<?php

use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Layout\Constraint;
use Phalanx\Theatron\Layout\Layout;
use Phalanx\Theatron\Region\RegionConfig;
use Phalanx\Theatron\Surface\Surface;
use Phalanx\Theatron\Surface\SurfaceConfig;
use Phalanx\Theatron\Terminal\Terminal;
use Phalanx\Theatron\Widget\Box;
use Phalanx\Theatron\Widget\ScrollableText;
use Phalanx\Theatron\Widget\StatusBar;
use Phalanx\Theatron\Widget\Text\Span;
use Phalanx\Theatron\Style\Style;
use React\EventLoop\Loop;

$config = new SurfaceConfig(terminal: Terminal::detect());
$surface = new Surface($config);

$fullArea = Rect::sized($surface->width, $surface->height);
[$mainArea, $statusArea] = Layout::vertical($fullArea, Constraint::fill(), Constraint::length(1));

$main = $surface->region('main', $mainArea);
$status = $surface->region('status', $statusArea);

$log = new ScrollableText();
$log->append('Application started.');

$bar = new StatusBar(Style::new()->bg('blue'));
$bar->setLeft(Span::styled(' Ready ', Style::new()->bold()->fg('white')));

$surface->onDraw(static function () use ($main, $status, $log, $bar): void {
    $main->draw(new Box($log, title: 'Log'));
    $status->draw($bar);
    $main->invalidate();
});

$surface->start();
Loop::run();
```

## Surface

`Surface` is the root rendering context. It owns the terminal lifecycle (raw mode, alternate screen, cursor visibility) and drives a periodic render loop via the ReactPHP event loop.

```php
<?php

use Phalanx\Theatron\Surface\ScreenMode;
use Phalanx\Theatron\Surface\Surface;
use Phalanx\Theatron\Surface\SurfaceConfig;
use Phalanx\Theatron\Terminal\Terminal;

$config = new SurfaceConfig(
    terminal: Terminal::detect(),
    mode: ScreenMode::Alternate,  // Alternate, Inline, or Detect
    contentFps: 30.0,             // Maximum content render rate
    structureFps: 10.0,           // Maximum structural render rate
    mouseTracking: false,         // Enable SGR mouse events
    bracketedPaste: true,         // Enable bracketed paste detection
);

$surface = new Surface($config);
$surface->start();
// ...
$surface->stop();
```

The render loop runs at the higher of `contentFps` and `structureFps`. On each tick, the `Compositor` checks which regions are dirty, blits their buffers into the frame buffer, diffs against the previous frame, and flushes only changed cells to the terminal via ANSI escape sequences. No full redraws unless the terminal resizes.

`Surface` handles `SIGWINCH` (terminal resize), `SIGINT`, and `SIGTERM` automatically when `ext-pcntl` is available. A shutdown function restores the terminal to its original state on unexpected exit.

## Regions

A `Region` is a positioned rectangular area within the surface. Each region has its own `Buffer`, a z-index for layering, and an independent tick rate:

```php
<?php

use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Region\RegionConfig;

// Create a region at position (0, 0), 80 columns wide, 20 rows tall
$main = $surface->region('main', Rect::of(0, 0, 80, 20));

// Region with custom config
$overlay = $surface->region('overlay', Rect::of(10, 5, 40, 10), new RegionConfig(
    tickRate: 10.0,  // Render at most 10 fps
    zIndex: 10,      // Draw above other regions
));

// Draw a widget into a region
$main->draw($myWidget);

// Stateful widgets pass state separately
$main->drawStateful($myStatefulWidget, $state);

// Mark dirty to trigger redraw on next tick
$main->invalidate();

// Resize dynamically
$main->resize(Rect::of(0, 0, 120, 40));

// Remove
$surface->removeRegion('overlay');
```

The `Compositor` manages all registered regions. It resolves z-order, checks each region's tick rate to decide if it should render this frame, and blits dirty region buffers into the surface's frame buffer.

## Layout

`Layout` splits a `Rect` into sub-areas using constraints. Two directions: `vertical` (split rows) and `horizontal` (split columns).

```php
<?php

use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Layout\Constraint;
use Phalanx\Theatron\Layout\Layout;

$area = Rect::sized(80, 24);

// Vertical: header (3 rows), content (fills remaining), footer (1 row)
[$header, $content, $footer] = Layout::vertical(
    $area,
    Constraint::length(3),
    Constraint::fill(),
    Constraint::length(1),
);

// Horizontal: sidebar (20 cols), main (fills remaining)
[$sidebar, $main] = Layout::horizontal(
    $content,
    Constraint::length(20),
    Constraint::fill(),
);

// Percentage-based
[$left, $right] = Layout::horizontal(
    $area,
    Constraint::percentage(30),
    Constraint::percentage(70),
);
```

Available constraints:

| Constraint | Effect |
|------------|--------|
| `Constraint::length(n)` | Exactly `n` rows/columns |
| `Constraint::percentage(p)` | `p`% of the total space |
| `Constraint::min(n)` | At least `n` rows/columns |
| `Constraint::max(n)` | At most `n` rows/columns |
| `Constraint::fill()` | Fill remaining space (distributes evenly among multiple fills) |

## Widgets

All widgets implement the `Widget` interface:

```php
<?php

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Widget\Widget;

interface Widget
{
    public function render(Rect $area, Buffer $buffer): void;
}
```

Widgets that need mutable state between renders implement `StatefulWidget` instead, receiving a state object on each `render()` call.

### Box

Wraps an inner widget with a border and optional title:

```php
<?php

use Phalanx\Theatron\Widget\Box;
use Phalanx\Theatron\Widget\BoxStyle;
use Phalanx\Theatron\Style\Style;

$region->draw(new Box(
    inner: $myWidget,
    border: BoxStyle::Double,    // Single, Double, Rounded, Heavy, or None
    title: 'Output',
    borderStyle: Style::new()->fg('cyan'),
    titleStyle: Style::new()->bold()->fg('white'),
));
```

### Table

Renders tabular data with auto-sized columns and styled headers:

```php
<?php

use Phalanx\Theatron\Widget\Table;
use Phalanx\Theatron\Style\Style;

$table = new Table(
    headers: ['Name', 'Status', 'Latency'],
    headerStyle: Style::new()->bold()->fg('cyan'),
);

$table->addRow('web-01', 'healthy', '12ms');
$table->addRow('web-02', 'degraded', '340ms');
$table->addRow('db-01', 'healthy', '3ms');

$region->draw($table);
```

Columns auto-size to content width. When content exceeds available space, columns proportionally shrink and long values truncate with `~`.

### ScrollableText

Scrollable text buffer with tail-follow behavior, ideal for log output and streaming content:

```php
<?php

use Phalanx\Theatron\Widget\ScrollableText;
use Phalanx\Theatron\Style\Style;

$log = new ScrollableText(maxLines: 10_000);

// Append full lines
$log->append('Server started on port 8080');
$log->append('Error: connection refused', Style::new()->fg('red'));

// Append tokens (no newline -- appends to current line)
$log->appendToken('Downloading... ');
$log->appendToken('done.', Style::new()->fg('green'));

// Scroll control
$log->scrollUp(5);
$log->scrollDown(5);
$log->scrollToBottom(); // Re-enables tail follow
```

When `followTail` is active (the default), new content auto-scrolls to the bottom. Scrolling up disables tail follow until `scrollToBottom()` is called.

### InputLine

Single-line text input with cursor movement, history, and readline-style key bindings:

```php
<?php

use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Widget\InputLine;

$input = new InputLine(prompt: '> ');

// Handle a key event -- returns the submitted text on Enter, null otherwise
$submitted = $input->handleKey($keyEvent);

if ($submitted !== null) {
    processCommand($submitted);
}
```

Supported key bindings: Left/Right (cursor), Ctrl+A/E (home/end), Ctrl+U/K (kill line), Ctrl+W (kill word), Alt+B/F (word boundaries), Up/Down (history), Backspace, Delete, and bracketed paste insertion.

### ProgressBar

Horizontal progress bar with percentage label:

```php
<?php

use Phalanx\Theatron\Widget\ProgressBar;
use Phalanx\Theatron\Style\Style;

$bar = new ProgressBar(
    filledStyle: Style::new()->fg('green'),
    emptyStyle: Style::new()->dim(),
);

$bar->setProgress(0.73); // 73%
$bar->setLabel('Uploading');

$region->draw($bar);
```

### Spinner

Animated spinner with configurable frame sets:

```php
<?php

use Phalanx\Theatron\Widget\Spinner;

$spinner = new Spinner(label: 'Loading...');

// Advance the animation frame (call on each render tick)
$spinner->tick();

$region->draw($spinner);
```

Built-in frame sets: dots (`DOTS`), line (`LINE`), and braille (`BRAILLE`). Pass a custom `frames` array for other animations.

### StatusBar

Left/right-aligned spans on a single row, typically used for status information:

```php
<?php

use Phalanx\Theatron\Widget\StatusBar;
use Phalanx\Theatron\Widget\Text\Span;
use Phalanx\Theatron\Style\Style;

$bar = new StatusBar(barStyle: Style::new()->bg('blue'));
$bar->setLeft(
    Span::styled(' NORMAL ', Style::new()->bold()->bg('green')->fg('black')),
    Span::plain(' main.php'),
);
$bar->setRight(
    Span::styled('Ln 42 Col 8 ', Style::new()->fg('white')),
);

$region->draw($bar);
```

### Sparkline

Miniature line chart using Unicode block characters:

```php
<?php

use Phalanx\Theatron\Widget\Sparkline;
use Phalanx\Theatron\Style\Style;

$spark = new Sparkline(style: Style::new()->fg('cyan'));
$spark->setData([1.0, 3.2, 2.1, 5.4, 4.8, 6.1, 3.3]);

// Or push values incrementally
$spark->push(7.2);

$region->draw($spark);
```

### CodeBlock

Syntax-highlighted code display with line numbers and a highlight marker:

```php
<?php

use Phalanx\Theatron\Widget\CodeBlock;

$code = new CodeBlock(
    code: $phpSource,
    startLine: 10,
    highlightLine: 15, // Mark line 15 with ">"
);

$region->draw($code);
```

Uses `PhpHighlighter` by default. Implement the `Highlighter` interface for other languages.

### Accordion

Expandable/collapsible sections, each wrapping an inner widget:

```php
<?php

use Phalanx\Theatron\Widget\Accordion;
use Phalanx\Theatron\Widget\AccordionSection;

$accordion = new Accordion();
$accordion->addSection(new AccordionSection('Server Info', $infoWidget, contentHeight: 5));
$accordion->addSection(new AccordionSection('Logs', $logWidget, contentHeight: 10));

// Toggle a section open/closed
$accordion->toggle(0);

$region->draw($accordion);
```

### Divider

Horizontal or vertical line separator:

```php
<?php

use Phalanx\Theatron\Widget\Divider;
use Phalanx\Theatron\Style\Style;

$region->draw(Divider::horizontal(Style::new()->dim()));
$region->draw(Divider::vertical(Style::new()->fg('cyan')));
```

## Styling

`Style` is an immutable builder for ANSI text attributes. It supports foreground/background colors, modifiers, and automatic downgrade across color modes (truecolor, 256-color, 16-color):

```php
<?php

use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style;

// Named colors
$style = Style::new()->fg('red')->bg('black')->bold();

// RGB / hex
$style = Style::new()->fg(Color::hex('#FF6B35'))->bg(Color::rgb(30, 30, 30));

// 256-color indexed
$style = Style::new()->fg(Color::indexed(208));

// Modifiers
$style = Style::new()->bold()->dim()->italic()->underline()->reverse()->strikethrough();

// Compose styles (later style overrides earlier)
$merged = $base->patch($override);
```

Color resolution happens at render time based on the detected `ColorMode`. A truecolor value renders as `38;2;r;g;b` in 24-bit terminals and degrades to the nearest 256-color or 16-color index in lesser terminals.

## Input Handling

`EventParser` decodes raw terminal input (ANSI escape sequences) into typed events. The `Surface` handles the plumbing -- register handlers for the event types you care about:

```php
<?php

use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\MouseEvent;
use Phalanx\Theatron\Input\PasteEvent;
use Phalanx\Theatron\Input\Key;

$surface->onMessage(KeyEvent::class, static function (KeyEvent $event) use ($input, $main): void {
    if ($event->is(Key::Escape)) {
        $surface->stop();
        return;
    }

    $submitted = $input->handleKey($event);

    if ($submitted !== null) {
        processCommand($submitted);
    }

    $main->invalidate();
});

$surface->onMessage(MouseEvent::class, static function (MouseEvent $event): void {
    // $event->button, $event->action, $event->x, $event->y
    // $event->ctrl, $event->alt, $event->shift
});

$surface->onMessage(PasteEvent::class, static function (PasteEvent $event) use ($input): void {
    $input->insertText($event->content);
});
```

`KeyEvent` carries the key (a `Key` enum value or a character string), plus modifier flags: `ctrl`, `alt`, `shift`. Use `$event->is(Key::Enter)` or `$event->is('a')` for matching.

Terminal resize fires a `ResizeEvent`:

```php
<?php

$surface->onResize(static function (int $width, int $height) use ($surface): void {
    // Recalculate layout with new dimensions
    $surface->invalidateAll();
});
```

## Terminal Detection

`Terminal::detect()` probes the environment once at boot and returns a `TerminalConfig`:

```php
<?php

use Phalanx\Theatron\Terminal\Terminal;

$config = Terminal::detect();
// $config->width, $config->height, $config->colorMode, $config->isTty
```

Detection checks `COLUMNS`/`LINES` environment variables, falls back to `stty size`, and resolves color mode from `COLORTERM`, `TERM`, `NO_COLOR`, and `CI`. Pass the resulting config to `SurfaceConfig` -- do not call `detect()` repeatedly.

## Custom Widgets

Implement `Widget` for stateless rendering or `StatefulWidget` when you need mutable state passed in:

```php
<?php

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Widget\Widget;

final class Clock implements Widget
{
    public function render(Rect $area, Buffer $buffer): void
    {
        $time = date('H:i:s');
        $style = Style::new()->bold()->fg('cyan');
        $buffer->putString($area->x, $area->y, $time, $style);
    }
}
```

The `Buffer` provides cell-level access (`set`, `get`, `putString`, `putLine`, `fill`, `blit`) and style-aware text rendering. Widgets should respect the `Rect` boundary and never write outside it.
