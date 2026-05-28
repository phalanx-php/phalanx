<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Screen;

use Phalanx\Theatron\Demos\Repl\ConversationLog;
use Phalanx\Theatron\Demos\Repl\Event\UserSubmitEvent;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyBinding;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Demos\Repl\Render\ConversationRenderer;
use Phalanx\Theatron\Demos\Repl\Render\InputRenderer;
use Phalanx\Theatron\Demos\Repl\Slice\AgentStatusSlice;
use Phalanx\Theatron\Demos\Repl\Slice\ConvoSlice;
use Phalanx\Theatron\Demos\Repl\Slice\InputSlice;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class ConversationScreen implements Screen
{
    public string $name { get => 'conversation'; }

    public function isOverlay(): bool { return false; }

    /** @var list<HotkeyBinding> */
    private array $bindings;

    public function __construct(
        private(set) ConversationRenderer $convo,
        private(set) InputRenderer $input,
        private(set) ConversationLog $log,
    ) {
        $convoLog = $log;
        $this->bindings = [
            new HotkeyBinding('p', ctrl: true, label: '^P:up', handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(ConvoSlice::class, static fn(ConvoSlice $s): ConvoSlice => $s->scrollUp());
            }),
            new HotkeyBinding('n', ctrl: true, label: '^N:down', handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(ConvoSlice::class, static fn(ConvoSlice $s): ConvoSlice => $s->scrollDown());
            }),
            new HotkeyBinding('d', ctrl: true, label: '^D:devtools', handler: static function (HotkeyContext $ctx): void {
                $ctx->stack->push('devtools');
            }),
            new HotkeyBinding('s', ctrl: true, label: '^S:settings', handler: static function (HotkeyContext $ctx): void {
                $ctx->stack->push('settings');
            }),
            new HotkeyBinding(Key::Enter, label: 'Enter:send', handler: static function (HotkeyContext $ctx) use ($convoLog): void {
                $convoState = $ctx->lens->handle(ConvoSlice::class)->value;

                if ($convoState->scrollOffset > 0) {
                    $ctx->writer->update(ConvoSlice::class, static fn(ConvoSlice $s): ConvoSlice => $s->expandAtScroll());
                    $convoState = $ctx->lens->handle(ConvoSlice::class)->value;

                    if ($convoState->expandedIndex !== null) {
                        $summary = $convoState->history[$convoState->expandedIndex] ?? null;

                        if ($summary !== null) {
                            $exchange = $convoLog->readAt($summary->lineOffset);

                            if ($exchange !== null) {
                                $ctx->writer->update(ConvoSlice::class, static fn(ConvoSlice $s): ConvoSlice => $s->withLoadedExchange($exchange));
                            }
                        }

                        $ctx->stack->push('focused-view');
                    }

                    return;
                }

                $currentText = $ctx->lens->handle(InputSlice::class)->value->text;

                if ($currentText === '') {
                    return;
                }

                $ctx->writer->update(InputSlice::class, static fn(InputSlice $s): InputSlice => $s->clear());

                $agentStatus = $ctx->lens->handle(AgentStatusSlice::class)->value->status;

                if ($agentStatus !== 'idle') {
                    $ctx->writer->update(InputSlice::class, static fn(InputSlice $s): InputSlice => $s->enqueue($currentText));
                } else {
                    $ctx->stream->emit(new UserSubmitEvent(message: $currentText));
                }
            }),
            new HotkeyBinding(Key::Backspace, handler: static function (HotkeyContext $ctx): void {
                $ctx->writer->update(InputSlice::class, static fn(InputSlice $s): InputSlice => $s->backspace());
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
        $char = $event->char();

        if ($char !== null && !$event->ctrl && !$event->alt) {
            $ctx->writer->update(InputSlice::class, static fn(InputSlice $s): InputSlice => $s->append($char));

            return true;
        }

        return false;
    }

    public function render(Ui $ui, HotkeyContext $ctx, int $width, int $height): Renderable
    {
        $convoHeight = max(1, $height - 6);

        $ruleWidth = min((int) ($width * 0.4), 30);
        $inputRule = Line::from(
            Span::styled('  ' . str_repeat("\u{2574}", $ruleWidth), TextStyle::new()->fg(Color::indexed(236))),
        );

        $spacer = $ui->text(Line::from(Span::plain('')), Style::of(size: Size::fixed(1)));

        $children = [
            $this->convo->render($ui, $width, $convoHeight),
            $spacer,
            $this->input->renderStatusLine($ui),
            $spacer,
            $this->input->renderInput($ui),
            $ui->text($inputRule, Style::of(size: Size::fixed(1))),
            $spacer,
        ];

        return new ColumnElement(children: $children);
    }
}
