<p align="center">
  <img src="assets/banner.svg" alt="Phalanx" width="520">
</p>

# Tui

Terminal UI and runtime framework for PHP 8.4+, built on the Phalanx
runtime.

Tui apps are invokable screens and components that return TDOM trees. The
runtime owns the terminal stage, input dispatch, navigation, dirty tracking, and
paint loop.

## Requirements

- PHP `^8.4`
- `ext-swoole`
- `ext-pcntl`
- `ext-mbstring`

## Install

```bash
composer install
```

## App Shape

Configure and start a terminal UI with `Tui::app()`. Apps provide their own
store, screens, bindings, and service bundles.

```php
<?php

use Phalanx\Tui\Inputs\Binding;
use Phalanx\Tui\Core\Screen;
use Phalanx\Tui\Reactive\Store;
use Phalanx\Tui\Tui;

/** @var list<class-string<Screen>> $screens */
$screens = [StatusScreen::class];
/** @var class-string<Store> $store */
$store = AppStore::class;

return Tui::app($context)
    ->store($store)
    ->screens($screens)
    ->globalBindings([
        Binding::ctrl('c')->quit()->label('quit'),
    ])
    ->run();
```

`Tui::app(...)` owns terminal stage configuration, screen registration,
input dispatch, and the Runtime startup path. The TUI runtime layer owns agent loop
contracts, messages, work state, prompts, reviews, and UI-facing state
projections.

## Runtime App Shape

Use `Tui::starting()` when the app is a runtime workspace. The
builder owns the default Runtime store, workspace screen, receive queue, input
submitter, boundary runner, and runtime tick loop.

```php
<?php

use Phalanx\Tui\Tui;

$assistant = new Assistant();

return Tui::starting($context)
    ->primary($assistant)
    ->run();
```

The default workspace renders projections from `Store`. User text enters
through `InputComposer`, becomes an `InputPromptSubmitter` prompt envelope, then
flows through the same receive path as other inlets.

## Components

A component implements `Component`. It receives a `RenderContext` and returns a
`Renderable` tree.

```php
<?php

use Phalanx\Tui\Core\RenderContext;
use Phalanx\Tui\Core\Component;
use Phalanx\Tui\Tdom\Renderable;

use function Phalanx\Tui\Kit\text;

class Greeting implements Component
{
    public function __construct(
        private(set) string $name = 'Leonidas',
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return text("Hail, {$this->name}.");
    }
}
```

Mount children with the free `mount()` helper. Runtime params are named props.

```php
<?php

use function Phalanx\Tui\Kit\mount;
use function Phalanx\Tui\Kit\panel;

public function __invoke(RenderContext $ctx): Renderable
{
    return panel(
        'Greeting',
        mount(Greeting::class, name: 'Themistocles'),
    );
}
```

Constructor params passed at mount time are runtime params. Constructor defaults
are used when no runtime value is supplied.

## Signals

Signals use method syntax. Read with `get()`, write with `set()`.

```php
<?php

use Phalanx\Tui\Core\RenderContext;
use Phalanx\Tui\Core\Component;
use Phalanx\Tui\Reactive\Signal;
use Phalanx\Tui\Tdom\Renderable;

use function Phalanx\Tui\Kit\text;

class Counter implements Component
{
    public function __construct(
        private(set) Signal $count = new Signal(0),
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return text('Count: ' . $this->count->get());
    }

    public function increment(): void
    {
        $this->count->update(static fn(int $current): int => $current + 1);
    }
}
```

Updater callbacks must be static closures. Non-closure callables are stored as
values. Subscribers must also be static closures.

Signals passed into a child component are borrowed. The child subscribes to them,
but it does not dispose them when it unmounts.

## Screens

A screen implements `Screen`. It receives a `ScreenContext`, which carries the
screen scope, theme, navigator, and mount system.

```php
<?php

use Phalanx\Tui\Core\ScreenContext;
use Phalanx\Tui\Core\Screen;
use Phalanx\Tui\Tdom\Renderable;

use function Phalanx\Tui\Kit\column;
use function Phalanx\Tui\Kit\text;

class DashboardScreen implements Screen
{
    public function __construct(
        private(set) AppStore $store,
    ) {
    }

    public function __invoke(ScreenContext $ctx): Renderable
    {
        return column(
            text('Dashboard'),
            text($this->store->status->message),
        );
    }
}
```

Screens can declare their own focus targets, status bar, and key bindings.

```php
<?php

use Phalanx\Tui\Inputs\Binding;
use Phalanx\Tui\Core\DeclaresBindings;
use Phalanx\Tui\Core\Focusable;
use Phalanx\Tui\Core\HasFocusables;
use Phalanx\Tui\Core\HasStatusBar;
use Phalanx\Tui\Core\Screen;
use Phalanx\Tui\Core\ScreenContext;
use Phalanx\Tui\Inputs\Key;
use Phalanx\Tui\Tdom\Renderable;

use function Phalanx\Tui\Kit\text;

class SettingsScreen implements Screen, HasStatusBar, HasFocusables, Focusable, DeclaresBindings
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return text('Settings');
    }

    public function statusBar(): Renderable
    {
        return text('  Space toggle  |  Esc back');
    }

    /** @return list<array{string, Focusable}> */
    public function focusables(): array
    {
        return [['settings', $this]];
    }

    /** @return list<Binding> */
    public function bindings(): array
    {
        return [
            Binding::key(Key::Space)->label('toggle'),
            Binding::key(Key::Escape)->back()->label('back'),
        ];
    }
}
```

Use `$ctx->navigator->go(SomeScreen::class)` to switch screens. `Escape` can be
bound to `Binding::key(Key::Escape)->back()` when a screen should return to the
previous workspace.

## Store

`Store` is a reactive state container organized into typed slices. Register each
slice once, expose it through property hooks, and update slices by assigning a
new immutable value.

```php
<?php

use Phalanx\Tui\Reactive\Store;

class StatusSlice
{
    public function __construct(
        private(set) string $message = 'Ready',
    ) {
    }

    public function withMessage(string $message): self
    {
        return new self($message);
    }
}

class AppStore extends Store
{
    public StatusSlice $status {
        get => $this->read(StatusSlice::class);
        set { $this->write(StatusSlice::class, $value); }
    }

    public function __construct()
    {
        $this->register(StatusSlice::class, new StatusSlice());
    }
}
```

Slices return new instances:

```php
<?php

$store->status = $store->status->withMessage('Connected');
```

`mutate()` still exists for batched slice updates:

```php
<?php

$store->mutate(
    StatusSlice::class,
    static fn(StatusSlice $slice): StatusSlice => $slice->withMessage('Connected'),
);
```

## Rendering with TDOM

Build terminal UI with free functions from `Phalanx\Tui\Kit`.

| Function | Element | Purpose |
|---|---|---|
| `text($content, $style)` | `TextElement` | Single line of text, plain string or styled `Line` |
| `panel($title, $child, $style)` | `PanelElement` | Bordered box with title and child content |
| `column(...$children)` | `ColumnElement` | Vertical stack |
| `row(...$children)` | `RowElement` | Horizontal layout |
| `grid($columns, ...$children)` | `GridElement` | Column-defined grid |
| `scrollable($content, $maxLines, $style)` | `ScrollElement` | Scrollable text region |
| `input($value, $prompt, $cursor, $style)` | `InputElement` | Text input field |
| `spinner($label, $frame, $style)` | `SpinnerElement` | Animated spinner |
| `statusLine(...$sections)` | `StatusLineElement` | Status bar sections |
| `divider($style)` | `DividerElement` | Horizontal rule |
| `progress($value, $label, $style)` | `ProgressElement` | Progress bar from `0.0` to `1.0` |

Example:

```php
<?php

use Phalanx\Tui\Styles\Border;
use Phalanx\Tui\Styles\Size;
use Phalanx\Tui\Styles\Color;
use Phalanx\Tui\Styles\Style as TextStyle;
use Phalanx\Tui\Tdom\Style;
use Phalanx\Tui\Styles\Line;
use Phalanx\Tui\Styles\Span;

use function Phalanx\Tui\Kit\column;
use function Phalanx\Tui\Kit\divider;
use function Phalanx\Tui\Kit\panel;
use function Phalanx\Tui\Kit\progress;
use function Phalanx\Tui\Kit\text;

public function __invoke(RenderContext $ctx): Renderable
{
    $header = text(Line::from(
        Span::styled('Tui', TextStyle::new()->fg(Color::indexed(250))->bold()),
        Span::plain(' ready'),
    ));

    $body = column(
        $header,
        progress(0.73, 'pipeline'),
        divider(),
        text('All tasks nominal.'),
    );

    return panel(
        'Dashboard',
        $body,
        Style::of(size: Size::fill(), border: Border::Single),
    );
}
```

Use `Line` and `Span` when text needs mixed styles. Use string input when plain
text is enough.

## Bindings

Bindings are fluent objects registered globally or by a screen/component that
implements `DeclaresBindings`.

```php
<?php

use Phalanx\Tui\Inputs\Binding;
use Phalanx\Tui\Inputs\Key;

[
    Binding::ctrl('c')->quit()->label('quit'),
    Binding::key(Key::Escape)->back()->label('back'),
]
```

Resolution is layered: overlay stack, active screen, global bindings. The active
binding list can be rendered with `$ctx->hints()` in component render contexts.

## Runtime Workspace

`WorkspaceScreen` is the current Runtime reference screen. It renders chat,
plan, runtime, DevTools, status, and input panels from store projections. Screens
do not call participants directly.

## Verification

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpcs
vendor/bin/rector process --dry-run
```
