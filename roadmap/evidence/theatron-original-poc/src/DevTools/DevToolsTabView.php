<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Kit\Metrics;
use Phalanx\Theatron\Reactor\ReactorState;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Border;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class DevToolsTabView implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $ui = $ctx->ui;
        $activeTab = $ctx->signal(0, key: 'devtools.tab');
        $tab = $activeTab->value;

        $tabBar = $this->renderTabBar($ctx, $tab);

        $body = match ($tab) {
            0 => $this->renderMetricsTab($ctx),
            1 => $this->renderSignalsTab($ctx),
            2 => $this->renderTreeTab($ctx),
            default => $this->renderMetricsTab($ctx),
        };

        return $ui->column(
            $tabBar,
            $ui->panel(
                '',
                $body,
                style: Style::of(size: Size::fill(), border: Border::Rounded, color: Color::indexed(208)),
            ),
        );
    }

    private static function label(string $name, string $value): Line
    {
        return Line::from(
            Span::styled(" {$name}:", TextStyle::new()->fg(Color::indexed(245))),
            Span::styled($value, TextStyle::new()->fg(Color::brightWhite())),
        );
    }

    /** @param array<string, ReactorState> $reactors */
    private static function reactorLine(array $reactors): Line
    {
        $spans = [Span::styled(' reactors:', TextStyle::new()->fg(Color::indexed(245)))];

        foreach ($reactors as $id => $state) {
            $color = match ($state) {
                ReactorState::Running => Color::brightGreen(),
                ReactorState::Restarting => Color::brightYellow(),
                ReactorState::Crashed, ReactorState::Exhausted => Color::brightRed(),
                ReactorState::Cancelled => Color::indexed(242),
                default => Color::indexed(250),
            };

            $spans[] = Span::styled(" {$id}:", TextStyle::new()->fg(Color::indexed(250)));
            $spans[] = Span::styled($state->value, TextStyle::new()->fg($color));
        }

        return Line::from(...$spans);
    }

    private function renderTabBar(StatefulContext $ctx, int $activeTab): Renderable
    {
        $tabs = ['Metrics', 'Signals', 'Tree'];
        $spans = [Span::styled(' ', TextStyle::new())];

        foreach ($tabs as $i => $label) {
            $style = $i === $activeTab
                ? TextStyle::new()->fg(Color::black())->bg(Color::indexed(208))->bold()
                : TextStyle::new()->fg(Color::indexed(245));

            $spans[] = Span::styled(" {$label} ", $style);
        }

        $spans[] = Span::styled(' 1/2/3:tab F12:overlay Ctrl+D:dock ', TextStyle::new()->fg(Color::indexed(242)));

        return $ctx->ui->text(Line::from(...$spans), Style::of(size: Size::fill()));
    }

    private function renderMetricsTab(StatefulContext $ctx): Renderable
    {
        $ui = $ctx->ui;
        $metrics = $ctx->lens(RuntimeMetricsSlice::class)->value;
        $scope = $ctx->lens(RuntimeScopeSlice::class)->value;
        $memory = $ctx->lens(RuntimeMemorySlice::class)->value;
        $reactorSlice = $ctx->lens(ReactorStateSlice::class)->value;
        $trace = $ctx->lens(StreamTraceSlice::class)->value;

        $rows = [];

        $rows[] = $ui->row(
            $ui->text(self::label('frames', (string) $metrics->frames), Style::of(size: Size::fixed(14))),
            $ui->text(self::label('tasks', (string) $metrics->tasks), Style::of(size: Size::fixed(12))),
            $ui->text(self::label('handles', (string) $metrics->handles), Style::of(size: Size::fixed(14))),
            $ui->text(self::label('events', (string) $metrics->events), Style::of(size: Size::fixed(14))),
        );

        $rows[] = $ui->row(
            $ui->text(self::label('real', Metrics::memory($memory->memReal)), Style::of(size: Size::fixed(14))),
            $ui->text(self::label('zend', Metrics::memory($memory->memZend)), Style::of(size: Size::fixed(14))),
            $ui->text(self::label('peak/r', Metrics::memory($memory->memRealPeak)), Style::of(size: Size::fixed(16))),
            $ui->text(self::label('peak/z', Metrics::memory($memory->memZendPeak)), Style::of(size: Size::fixed(16))),
        );

        $runId = $scope->currentRunId === '' ? 'none' : $scope->currentRunId;
        $runState = $scope->currentRunState === '' ? 'none' : $scope->currentRunState;
        $dimStyle = TextStyle::new()->fg(Color::indexed(245));
        $brightStyle = TextStyle::new()->fg(Color::brightWhite());

        $rows[] = $ui->text(Line::from(
            Span::styled(' scope:', $dimStyle),
            Span::styled((string) $scope->activeScopes, $brightStyle),
            Span::styled(' run:', $dimStyle),
            Span::styled($runId, $brightStyle),
            Span::styled(' state:', $dimStyle),
            Span::styled($runState, $brightStyle),
        ));

        if ($reactorSlice->reactors !== []) {
            $rows[] = $ui->text(self::reactorLine($reactorSlice->reactors));
        }

        $latest = $trace->latest();
        if ($latest !== null) {
            $shortClass = substr(strrchr($latest->eventClass, '\\') ?: $latest->eventClass, 1);
            $rows[] = $ui->text(Line::from(
                Span::styled(' last:', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled($shortClass, TextStyle::new()->fg(Color::brightCyan())),
                Span::styled(" ({$latest->subscriberCount} subs)", TextStyle::new()->fg(Color::indexed(242))),
            ));
        }

        return $ui->column(...$rows);
    }

    private function renderSignalsTab(StatefulContext $ctx): Renderable
    {
        $ui = $ctx->ui;
        $signalSlice = $ctx->lens(SignalRegistrySlice::class)->value;
        $rows = [];

        $rows[] = $ui->text(Line::from(
            Span::styled(' Signal', TextStyle::new()->fg(Color::indexed(245))->bold()),
            Span::styled(str_repeat(' ', 20), TextStyle::new()),
            Span::styled('Value', TextStyle::new()->fg(Color::indexed(245))->bold()),
            Span::styled(str_repeat(' ', 20), TextStyle::new()),
            Span::styled('Subs', TextStyle::new()->fg(Color::indexed(245))->bold()),
        ));

        foreach ($signalSlice->signals as $snapshot) {
            $nameColor = $snapshot->isDisposed ? Color::indexed(242) : Color::brightCyan();
            $valueColor = $snapshot->isDisposed ? Color::indexed(242) : Color::brightWhite();

            $rows[] = $ui->text(Line::from(
                Span::styled(" {$snapshot->label}", TextStyle::new()->fg($nameColor)),
                Span::styled(' = ', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled($snapshot->value, TextStyle::new()->fg($valueColor)),
                Span::styled(" ({$snapshot->subscriberCount})", TextStyle::new()->fg(Color::indexed(242))),
            ));
        }

        if ($signalSlice->signals === []) {
            $rows[] = $ui->text(Line::from(
                Span::styled(' No signals registered', TextStyle::new()->fg(Color::indexed(242))),
            ));
        }

        return $ui->column(...$rows);
    }

    private function renderTreeTab(StatefulContext $ctx): Renderable
    {
        $ui = $ctx->ui;
        $treeSlice = $ctx->lens(ComponentTreeSlice::class)->value;
        $rows = [];

        foreach ($treeSlice->nodes as $node) {
            $indent = str_repeat('  ', $node->depth);
            $shortClass = substr(strrchr($node->class, '\\') ?: $node->class, 1);

            $rows[] = $ui->text(Line::from(
                Span::styled(" {$indent}", TextStyle::new()),
                Span::styled($node->depth > 0 ? '|- ' : '', TextStyle::new()->fg(Color::indexed(242))),
                Span::styled($node->name, TextStyle::new()->fg(Color::brightCyan())->bold()),
                Span::styled(" ({$shortClass})", TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(" sig:{$node->signalCount}", TextStyle::new()->fg(Color::indexed(242))),
                Span::styled(" sub:{$node->subscriptionCount}", TextStyle::new()->fg(Color::indexed(242))),
            ));
        }

        if ($treeSlice->nodes === []) {
            $rows[] = $ui->text(Line::from(
                Span::styled(' No components registered', TextStyle::new()->fg(Color::indexed(242))),
            ));
        }

        return $ui->column(...$rows);
    }
}
