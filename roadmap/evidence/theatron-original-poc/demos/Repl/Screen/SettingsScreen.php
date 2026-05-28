<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Screen;

use Phalanx\Theatron\Demos\Repl\Input\HotkeyBinding;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Demos\Repl\Render\SettingsPage;
use Phalanx\Theatron\Demos\Repl\Slice\AgentStatusSlice;
use Phalanx\Theatron\Demos\Repl\Slice\SettingsSlice;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;

class SettingsScreen implements Screen
{
    public string $name { get => 'settings'; }

    public function isOverlay(): bool { return false; }

    /** @var list<HotkeyBinding> */
    private array $bindings;

    public function __construct(
        private(set) SettingsPage $renderer,
    ) {
        $this->bindings = [
            new HotkeyBinding(Key::Left, label: "\u{2190}:tab", handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(SettingsSlice::class, static fn(SettingsSlice $s): SettingsSlice => $s->prevTab());
            }),
            new HotkeyBinding(Key::Right, label: "\u{2192}:tab", handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(SettingsSlice::class, static fn(SettingsSlice $s): SettingsSlice => $s->nextTab());
            }),
            new HotkeyBinding(Key::Up, label: "\u{2191}/\u{2193}:item", handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(SettingsSlice::class, static fn(SettingsSlice $s): SettingsSlice => $s->prevItem());
            }),
            new HotkeyBinding(Key::Down, handler: static function (HotkeyContext $ctx): void {
                $settings = $ctx->lens->handle(SettingsSlice::class)->value;
                $max = $settings->activeTab->itemCount();
                $ctx->writer->update(SettingsSlice::class, static fn(SettingsSlice $s): SettingsSlice => $s->nextItem($max));
            }),
            new HotkeyBinding(Key::Space, label: 'Space:toggle', handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(SettingsSlice::class, static fn(SettingsSlice $s): SettingsSlice => $s->toggleSelected());
            }),
            new HotkeyBinding(Key::Enter, handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(SettingsSlice::class, static fn(SettingsSlice $s): SettingsSlice => $s->toggleSelected());
            }),
        ];
    }

    /** @return list<HotkeyBinding> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    public function handleInput(KeyEvent $event, HotkeyContext $ctx): bool
    {
        return false;
    }

    public function render(Ui $ui, HotkeyContext $ctx, int $width, int $height): Renderable
    {
        $settings = $ctx->lens->handle(SettingsSlice::class)->value;
        $modelName = $ctx->lens->handle(AgentStatusSlice::class)->value->modelName;

        return $this->renderer->render($ui, $settings, $width, $height, $modelName);
    }
}
