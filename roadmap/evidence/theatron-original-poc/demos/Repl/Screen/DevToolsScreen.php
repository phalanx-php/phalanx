<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Screen;

use Phalanx\Theatron\Demos\Repl\Input\HotkeyBinding;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Demos\Repl\Render\DevToolsOverlay;
use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestSlice;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;

class DevToolsScreen implements Screen
{
    public string $name { get => 'devtools'; }

    public function isOverlay(): bool { return false; }

    /** @var list<HotkeyBinding> */
    private array $bindings;

    public function __construct(
        private(set) DevToolsOverlay $renderer,
    ) {
        $this->bindings = [
            new HotkeyBinding(Key::Up, label: "\u{2191}:req", handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(LlmRequestSlice::class, static fn(LlmRequestSlice $s): LlmRequestSlice => $s->focusUp());
            }),
            new HotkeyBinding(Key::Down, label: "\u{2193}:req", handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(LlmRequestSlice::class, static fn(LlmRequestSlice $s): LlmRequestSlice => $s->focusDown());
            }),
            new HotkeyBinding(Key::Enter, label: 'Enter:detail', handler: static function (HotkeyContext $ctx): void {
                $requests = $ctx->lens->handle(LlmRequestSlice::class)->value;

                if ($requests->focused() !== null) {
                    $ctx->writer->update(LlmRequestSlice::class, static fn(LlmRequestSlice $s): LlmRequestSlice => $s->resetDetailScroll());
                    $ctx->stack->push('llm-request-detail');
                }
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
        return $this->renderer->render($ui, $width, $height);
    }
}
