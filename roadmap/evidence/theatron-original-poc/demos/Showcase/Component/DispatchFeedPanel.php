<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Component;

use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentRosterSlice;
use Phalanx\Theatron\Demos\Showcase\Slice\DispatchFeedSlice;
use Phalanx\Theatron\Demos\Showcase\Slice\FeedMessage;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class DispatchFeedPanel implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $feed = $ctx->lens(DispatchFeedSlice::class);
        $roster = $ctx->lens(AgentRosterSlice::class);
        $ui = $ctx->ui;

        $rows = [];
        $messages = $feed->value->allMessages();
        $agents = $roster->value->agents;

        if ($messages === []) {
            $rows[] = $ui->text(
                'Waiting for agent output...',
                Style::of(size: Size::fixed(1), color: Color::indexed(242)),
            );
        }

        foreach ($messages as $msg) {
            $agentName = isset($agents[$msg->agentId]) ? $agents[$msg->agentId]->name : $msg->agentId;
            $rows[] = $ui->text(self::messageLine($agentName, $msg), Style::of(size: Size::fixed(1)));

            $textLines = explode("\n", $msg->text);
            foreach ($textLines as $textLine) {
                $color = $msg->streaming ? Color::brightWhite() : Color::indexed(252);
                $rows[] = $ui->text(
                    Line::from(Span::styled("  {$textLine}", TextStyle::new()->fg($color))),
                    Style::of(size: Size::fixed(1)),
                );
            }

            $rows[] = $ui->text('', Style::of(size: Size::fixed(1)));
        }

        $content = $ui->column(...$rows);

        return $ui->panel(
            'Dispatch Feed',
            $content,
            style: Style::of(
                size: Size::fill(),
                border: Border::Rounded,
                color: Color::brightGreen(),
            ),
        );
    }

    private static function messageLine(string $agentName, FeedMessage $msg): Line
    {
        $prefix = $msg->streaming ? '>> ' : '   ';
        $nameColor = $msg->streaming ? Color::brightYellow() : Color::brightCyan();

        return Line::from(
            Span::styled($prefix, TextStyle::new()->fg(Color::indexed(240))),
            Span::styled($agentName, TextStyle::new()->fg($nameColor)->bold()),
        );
    }
}
