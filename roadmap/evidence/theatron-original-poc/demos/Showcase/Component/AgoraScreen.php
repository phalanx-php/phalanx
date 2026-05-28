<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Showcase\Component;

use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentMetricsSlice;
use Phalanx\Theatron\Demos\Showcase\Slice\AgentRosterSlice;
use Phalanx\Theatron\Kit\Metrics;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class AgoraScreen implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $metrics = $ctx->lens(AgentMetricsSlice::class);
        $roster = $ctx->lens(AgentRosterSlice::class);
        $ui = $ctx->ui;

        $rail = (new AgentRailPanel())($ctx);
        $feed = (new DispatchFeedPanel())($ctx);

        $main = $ui->row($rail, $feed);

        $metricsVal = $metrics->value;
        $totalAgents = count($roster->value->agents);
        $mem = Metrics::memory(memory_get_usage(true));

        $statusBar = $ui->statusLine(
            $ui->text(
                Line::from(Span::styled(
                    " PHALANX SHOWCASE ",
                    TextStyle::new()->fg(Color::black())->bg(Color::brightCyan())->bold(),
                )),
                Style::of(size: Size::fixed(20)),
            ),
            $ui->text(
                self::metricSpan('agents', "{$metricsVal->completedAgents}/{$totalAgents}"),
                Style::of(size: Size::fixed(16)),
            ),
            $ui->text(
                self::metricSpan('tokens', (string) $metricsVal->totalTokens),
                Style::of(size: Size::fixed(14)),
            ),
            $ui->text(
                self::metricSpan('mem', $mem),
                Style::of(size: Size::fixed(14)),
            ),
            $ui->text(
                Line::from(Span::styled(
                    ' q:quit ',
                    TextStyle::new()->fg(Color::indexed(242)),
                )),
                Style::of(size: Size::fill()),
            ),
        );

        return $ui->column($main, $statusBar);
    }

    private static function metricSpan(string $label, string $value): Line
    {
        return Line::from(
            Span::styled(" {$label}:", TextStyle::new()->fg(Color::indexed(245))),
            Span::styled($value, TextStyle::new()->fg(Color::brightWhite())),
        );
    }
}
