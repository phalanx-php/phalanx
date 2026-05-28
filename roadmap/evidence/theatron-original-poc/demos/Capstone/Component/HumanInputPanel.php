<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Component;

use Phalanx\Theatron\Demos\Capstone\Event\AgentMessageEvent;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputTarget;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Stream\TheatronStream;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;

final class HumanInputPanel implements InputTarget
{
    private string $text = '';

    public function __construct(
        private(set) TheatronStream $stream,
    ) {
    }

    public function handleInput(InputEvent $event): bool
    {
        if (!$event instanceof KeyEvent) {
            return false;
        }

        if ($event->is(Key::Enter) && $this->text !== '') {
            $this->submit();

            return true;
        }

        if ($event->is(Key::Backspace)) {
            $this->text = mb_substr($this->text, 0, -1);

            return true;
        }

        if ($event->is(Key::Space)) {
            $this->text .= ' ';

            return true;
        }

        $char = $event->char();

        if ($char === null) {
            return false;
        }

        $this->text .= $char;

        return true;
    }

    public function render(Ui $ui, bool $focused): Renderable
    {
        $borderColor = $focused ? Color::brightGreen() : Color::indexed(240);

        return $ui->panel(
            'Command',
            $ui->input(
                value: $this->text,
                prompt: 'human> ',
                cursor: mb_strlen($this->text),
                style: Style::of(size: Size::fixed(1)),
            ),
            style: Style::of(
                size: Size::fixed(3),
                border: Border::Rounded,
                color: $borderColor,
            ),
        );
    }

    private function submit(): void
    {
        $this->stream->emit(new AgentMessageEvent(
            agentId: 'human',
            body: $this->text,
            timestamp: microtime(true),
        ));
        $this->text = '';
    }
}
