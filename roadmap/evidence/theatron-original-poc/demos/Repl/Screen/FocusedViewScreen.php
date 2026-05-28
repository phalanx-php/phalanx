<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Screen;

use Phalanx\Theatron\Demos\Repl\Input\HotkeyBinding;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Demos\Repl\Render\FocusedViewRenderer;
use Phalanx\Theatron\Demos\Repl\Slice\ConvoSlice;
use Phalanx\Theatron\Demos\Repl\Slice\FocusedPane;
use Phalanx\Theatron\Demos\Repl\Slice\FocusedViewSlice;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;

class FocusedViewScreen implements Screen
{
    public string $name { get => 'focused-view'; }

    public function isOverlay(): bool { return false; }

    /** @var list<HotkeyBinding> */
    private array $bindings;

    public function __construct(
        private(set) FocusedViewRenderer $renderer,
    ) {
        $this->bindings = [
            new HotkeyBinding(Key::Up, label: 'Up:scroll', handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(FocusedViewSlice::class, static fn(FocusedViewSlice $s): FocusedViewSlice => $s->scrollTo(max(0, $s->scrollPosition - 1)));
            }),
            new HotkeyBinding(Key::Down, label: 'Dn:scroll', handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(FocusedViewSlice::class, static fn(FocusedViewSlice $s): FocusedViewSlice => $s->scrollTo($s->scrollPosition + 1));
            }),
            new HotkeyBinding('q', ctrl: true, label: '^Q:question', handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(FocusedViewSlice::class, static fn(FocusedViewSlice $s): FocusedViewSlice => $s->switchPane(FocusedPane::Question));
            }),
            new HotkeyBinding('a', ctrl: true, label: '^A:answer', handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(FocusedViewSlice::class, static fn(FocusedViewSlice $s): FocusedViewSlice => $s->switchPane(FocusedPane::Answer));
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
        $convoState = $ctx->lens->handle(ConvoSlice::class)->value;
        $focused = $ctx->lens->handle(FocusedViewSlice::class)->value;

        if ($convoState->lastExchange === null) {
            return $ui->text(
                Line::plain('  No exchange selected.'),
                Style::of(size: Size::fixed(1)),
            );
        }

        $rows = $this->renderer->render($ui, $convoState->lastExchange, $focused, $width, $height);

        return new ColumnElement($rows, Style::of(size: Size::fill()));
    }
}
