<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Component;

use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentEntry;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentRosterSlice;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class AgentRailPanel implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $ui = $ctx->ui;
        $roster = $ctx->lens(AgentRosterSlice::class);

        $rows = [];

        foreach ($roster->value->agents as $agent) {
            $rows[] = $ui->text(self::agentLine($agent), Style::of(size: Size::fixed(1)));
            $rows[] = $ui->text(
                self::statusLine($agent),
                Style::of(size: Size::fixed(1)),
            );
            $rows[] = $ui->text('', Style::of(size: Size::fixed(1)));
        }

        if ($rows === []) {
            $rows[] = $ui->text('No agents', Style::of(color: Color::gray()));
        }

        return $ui->panel(
            'Agents',
            $ui->column(...$rows),
            style: Style::of(
                size: Size::fixed(28),
                border: Border::Rounded,
                color: Color::brightCyan(),
            ),
        );
    }

    private static function agentLine(AgentEntry $agent): Line
    {
        $indicator = match ($agent->status) {
            'thinking' => Span::styled('* ', TextStyle::new()->fg(Color::brightYellow())),
            'complete' => Span::styled('+ ', TextStyle::new()->fg(Color::brightGreen())),
            'error' => Span::styled('! ', TextStyle::new()->fg(Color::brightRed())),
            default => Span::styled('- ', TextStyle::new()->fg(Color::gray())),
        };

        return Line::from(
            $indicator,
            Span::styled($agent->name, TextStyle::new()->fg(Color::brightWhite())),
        );
    }

    private static function statusLine(AgentEntry $agent): Line
    {
        $status = "  {$agent->role} [{$agent->provider}]";

        $color = match ($agent->status) {
            'thinking' => Color::brightYellow(),
            'complete' => Color::indexed(242),
            default => Color::indexed(240),
        };

        $line = Line::from(Span::styled($status, TextStyle::new()->fg($color)));

        if ($agent->tokens > 0) {
            $line = $line->append(Span::styled(
                " {$agent->tokens}tk",
                TextStyle::new()->fg(Color::indexed(245)),
            ));
        }

        return $line;
    }
}
