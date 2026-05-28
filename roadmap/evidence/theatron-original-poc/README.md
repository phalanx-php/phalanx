# Phalanx Theatron PoC

This repo is the original proof-of-concept for Theatron's terminal renderer,
input parsing, focus quirks, and REPL UX. Keep it around as implementation
reference material. Do not use its old `StatefulComponent`, `$ctx->signal()`, or
`$signal->value` API shape for new work.

Current application syntax lives in `projects/theatron`.

## Current v2 Shape

The v2 package uses invokable screens/components, typed contexts, `Signal::get()`
/ `Signal::set()`, typed store slices, workspace screens, and fluent bindings.

```php
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Template\AppStore;
use Phalanx\Theatron\Template\Screen\ChatScreen;
use Phalanx\Theatron\Template\Screen\DevToolsScreen;
use Phalanx\Theatron\Template\Screen\SettingsScreen;
use Phalanx\Theatron\Theatron;

$app = Theatron::app($context)
    ->store(AppStore::class)
    ->screens([
        ChatScreen::class,
        DevToolsScreen::class,
        SettingsScreen::class,
    ])
    ->globalBindings([
        Binding::ctrl('c')->quit()->label('quit'),
        Binding::ctrl('d')->workspace(DevToolsScreen::class)->label('devtools'),
        Binding::ctrl('s')->workspace(SettingsScreen::class)->label('settings'),
    ])
    ->devtools(true)
    ->build();
```

## Components

Components implement `Component`, receive `RenderContext`, and return a TDOM
`Renderable`.

```php
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Tdom\Renderable;

final class Greeting implements Component
{
    public function __construct(
        private(set) string $name = 'Leonidas',
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return $ctx->ui->text("Hail, {$this->name}.");
    }
}
```

Mount children through the context:

```php
public function __invoke(RenderContext $ctx): Renderable
{
    return $ctx->ui->panel(
        'Greeting',
        $ctx->mount(Greeting::class, name: 'Themistocles')->render($ctx),
    );
}
```

## Signals

Signals use method syntax.

```php
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Tdom\Renderable;

final class Counter implements Component
{
    public function __construct(
        private(set) Signal $count = new Signal(0),
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return $ctx->ui->text('Count: ' . $this->count->get());
    }

    public function increment(): void
    {
        $this->count->set(static fn(int $current): int => $current + 1);
    }
}
```

## Screens

Screens implement `Screen`, receive `ScreenContext`, and can declare status bars,
focus targets, and bindings.

```php
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\DeclaresBindings;
use Phalanx\Theatron\Contract\Focusable;
use Phalanx\Theatron\Contract\HasFocusables;
use Phalanx\Theatron\Contract\HasStatusBar;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;

final class SettingsScreen implements Screen, HasStatusBar, HasFocusables, DeclaresBindings
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return $ctx->ui->text('Settings');
    }

    public function statusBar(Ui $ui): Renderable
    {
        return $ui->text('  Space toggle  |  Esc back');
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

Use `$ctx->navigator->go(SomeScreen::class)` for workspace navigation. DevTools,
request detail, and settings are screens in v2, not overlays.

## Store

The current store model uses typed slices and property hooks.

```php
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

Update slices by assigning the returned immutable value:

```php
$store->conversation = $store->conversation->addUserMessage($text);
$store->activity = $store->activity->withStatus(ActivityStatus::Running);
```

## What This PoC Still Proves

- Terminal cell buffer and differential ANSI writer behavior.
- Event parser behavior for keyboard, paste, mouse, and resize input.
- TDOM layout and painting constraints.
- REPL UI quirks that informed the v2 template app, especially conversation
  history, composer spacing, hotkey context, DevTools pages, and request preview
  screens.

For current implementation and verification commands, use `projects/theatron`.
