# Theatron

Async terminal UI framework for PHP 8.4+, built on the Phalanx runtime.

Theatron apps are invokable screens and components that return TDOM trees. The
runtime owns the terminal stage, input dispatch, navigation, dirty tracking, and
paint loop.

## Requirements

- PHP `^8.4`
- `ext-openswoole` `^26.2`
- `ext-pcntl`
- `ext-mbstring`

## Install

```bash
composer install
```

Run the bundled template app:

```bash
php bin/theatron
```

Run the non-interactive pipeline demo:

```bash
php demos/full.php
```

## Current App Shape

Configure and start the app with `Theatron::app()`. External runtime bundles are
registered directly on the same builder.

```php
<?php

use Phalanx\Iris\HttpServiceBundle;
use Phalanx\Theatron\Agent\AthenaServiceBundle;
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Theatron;
use Phalanx\Theatron\TheatronApp;
use Phalanx\Theatron\TheatronServiceBundle;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Screen\ChatScreen;
use Phalanx\Theatron\Template\Screen\ConversationBlockDetailScreen;
use Phalanx\Theatron\Template\Screen\DevToolsScreen;
use Phalanx\Theatron\Template\Screen\SettingsScreen;

$screens = [
    ChatScreen::class,
    ConversationBlockDetailScreen::class,
    DevToolsScreen::class,
    SettingsScreen::class,
];

return Theatron::app($context)
    ->store(AppStore::class)
    ->screens($screens)
    ->globalBindings([
        Binding::ctrl('c')->quit()->label('quit'),
    ])
    ->providers(
        static fn(TheatronApp $app): TheatronServiceBundle => new TheatronServiceBundle($app),
        new HttpServiceBundle(),
        AthenaServiceBundle::ollama(),
    )
    ->devtools()
    ->run();
```

`Theatron::app(...)` owns the Theatron app configuration and Aegis startup path.
Runtime bundles are declared in the bootstrap. The Theatron bundle receives the
built app, so it registers the same stage/store instances the renderer uses.
The bundled app uses Ollama through Athena by default. Override
`THEATRON_OLLAMA_BASE_URL`, `THEATRON_OLLAMA_MODEL`, or
`THEATRON_MAX_INVOCATIONS` in the runtime context when needed.

## Components

A component implements `Component`. It receives a `RenderContext` and returns a
`Renderable` tree.

```php
<?php

use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Tdom\Renderable;

use function Phalanx\Theatron\Ui\text;

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

use function Phalanx\Theatron\Ui\mount;
use function Phalanx\Theatron\Ui\panel;

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

use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Tdom\Renderable;

use function Phalanx\Theatron\Ui\text;

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
        $this->count->set(static fn(int $current): int => $current + 1);
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

use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Template\AppStore;

use function Phalanx\Theatron\Ui\column;
use function Phalanx\Theatron\Ui\text;

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
            text('Messages: ' . count($this->store->conversation->messages)),
        );
    }
}
```

Screens can declare their own focus targets, status bar, and key bindings.

```php
<?php

use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Contract\DeclaresBindings;
use Phalanx\Theatron\Contract\Focusable;
use Phalanx\Theatron\Contract\HasFocusables;
use Phalanx\Theatron\Contract\HasStatusBar;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Tdom\Renderable;

use function Phalanx\Theatron\Ui\text;

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

use Phalanx\Theatron\State\Store;
use Phalanx\Theatron\Template\Slice\ActivitySlice;
use Phalanx\Theatron\Template\Slice\ConversationSlice;

final class AppStore extends Store
{
    public ConversationSlice $conversation {
        get => $this->read(ConversationSlice::class);
        set { $this->write(ConversationSlice::class, $value); }
    }

    public ActivitySlice $activity {
        get => $this->read(ActivitySlice::class);
        set { $this->write(ActivitySlice::class, $value); }
    }

    public function __construct()
    {
        $this->register(ConversationSlice::class, new ConversationSlice());
        $this->register(ActivitySlice::class, new ActivitySlice());
    }
}
```

Slices return new instances:

```php
<?php

$store->conversation = $store->conversation->addUserMessage($text);
$store->activity = $store->activity->withStatus(ActivityStatus::Running);
```

`mutate()` still exists for batched slice updates:

```php
<?php

$store->mutate(
    ConversationSlice::class,
    static fn(ConversationSlice $slice): ConversationSlice => $slice->addUserMessage($text),
);
```

## Rendering with TDOM

Build terminal UI with free functions from `Phalanx\Theatron\Ui`.

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

use Phalanx\Theatron\Layout\Border;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

use function Phalanx\Theatron\Ui\column;
use function Phalanx\Theatron\Ui\divider;
use function Phalanx\Theatron\Ui\panel;
use function Phalanx\Theatron\Ui\progress;
use function Phalanx\Theatron\Ui\text;

public function __invoke(RenderContext $ctx): Renderable
{
    $header = text(Line::from(
        Span::styled('Theatron', TextStyle::new()->fg(Color::indexed(250))->bold()),
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

use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Input\Key;

[
    Binding::ctrl('c')->quit()->label('quit'),
    Binding::key(Key::Escape)->back()->label('back'),
]
```

Resolution is layered: overlay stack, active screen, global bindings. The active
binding list can be rendered with `$ctx->hints()` in component render contexts.

## Template Screens

The bundled template app is the current REPL-style reference:

- `ChatScreen` -- conversation history, active exchange, shell-style input
  composer, queue undo chords, thinking status, and bottom controls.
- `ConversationBlockDetailScreen` -- focused conversation block show page.
- `DevToolsScreen` -- Metrics, Requests, Signals, Tree, and Store tabs for runtime
  inspection.
- `LlmRequestDetailScreen` -- request/response body preview with JSON highlighting.
- `SettingsScreen` -- tabbed General/Tools/MCP/Model/Display settings backed by
  `SettingsSlice`.

DevTools and settings are workspace screens, not overlays.

## Verification

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpcs
vendor/bin/rector process --dry-run
php demos/full.php
```
