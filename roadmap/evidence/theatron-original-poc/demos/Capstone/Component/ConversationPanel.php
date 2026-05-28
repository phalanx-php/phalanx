<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Component;

use Phalanx\Theatron\Demos\Capstone\Slice\ConversationMessage;
use Phalanx\Theatron\Demos\Capstone\Slice\ConversationSlice;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\NormalModeHandler;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class ConversationPanel implements NormalModeHandler
{
    private int $scroll = 0;

    public function __construct(
        private(set) Lens $lens,
        private(set) int $visibleLines = 12,
    ) {
    }

    public function handleNormalKey(KeyEvent $event): bool
    {
        $messages = $this->lens->handle(ConversationSlice::class)->value->messages;
        $max = max(0, count($messages) - $this->visibleLines);

        if ($event->is('j') || $event->is(Key::Down)) {
            $this->scroll = min($max, $this->scroll + 1);

            return true;
        }

        if ($event->is('k') || $event->is(Key::Up)) {
            $this->scroll = max(0, $this->scroll - 1);

            return true;
        }

        if ($event->is('G')) {
            $this->scroll = $max;

            return true;
        }

        return false;
    }

    public function render(Ui $ui, bool $focused): Renderable
    {
        /** @var ConversationSlice $slice */
        $slice = $this->lens->handle(ConversationSlice::class)->value;
        $messages = $slice->messages;

        $total = count($messages);
        $maxScroll = max(0, $total - $this->visibleLines);
        $this->scroll = min($this->scroll, $maxScroll);

        $visible = array_slice($messages, $this->scroll, $this->visibleLines);
        $rows = [];

        foreach ($visible as $msg) {
            $rows[] = $this->renderMessage($ui, $msg);
        }

        if ($rows === []) {
            $rows[] = $ui->text(
                Line::from(Span::styled('  No messages yet.', TextStyle::new()->fg(Color::indexed(242)))),
            );
        }

        $borderColor = $focused ? Color::brightYellow() : Color::indexed(240);

        return $ui->panel('Conversation', $ui->column(...$rows), style: Style::of(
            size: Size::fill(),
            border: Border::Rounded,
            color: $borderColor,
        ));
    }

    private static function agentColor(string $agentId): Color
    {
        return match ($agentId) {
            'researcher' => Color::brightCyan(),
            'analyst' => Color::brightMagenta(),
            'steward' => Color::brightGreen(),
            'human' => Color::brightWhite(),
            default => Color::indexed(250),
        };
    }

    private function renderMessage(Ui $ui, ConversationMessage $msg): Renderable
    {
        $nameColor = self::agentColor($msg->from);

        return $ui->text(Line::from(
            Span::styled(" {$msg->from}", TextStyle::new()->fg($nameColor)->bold()),
            Span::styled(': ', TextStyle::new()->fg(Color::indexed(245))),
            Span::styled($msg->body, TextStyle::new()->fg(Color::indexed(252))),
        ));
    }
}
