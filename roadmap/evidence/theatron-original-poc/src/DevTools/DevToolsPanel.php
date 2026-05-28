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

final class DevToolsPanel implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $ui = $ctx->ui;
        $metrics = $ctx->lens(RuntimeMetricsSlice::class)->value;
        $scope = $ctx->lens(RuntimeScopeSlice::class)->value;
        $memory = $ctx->lens(RuntimeMemorySlice::class)->value;
        $reactorSlice = $ctx->lens(ReactorStateSlice::class)->value;
        $trace = $ctx->lens(StreamTraceSlice::class)->value;

        $header = $ui->row(
            $ui->text(
                Line::from(
                    Span::styled(' DevTools ', TextStyle::new()->fg(Color::black())->bg(Color::indexed(208))->bold()),
                    Span::styled(' Ctrl+D:toggle ', TextStyle::new()->fg(Color::indexed(242))),
                ),
                Style::of(size: Size::fill()),
            ),
        );

        $metricsRow = $ui->row(
            $ui->text(
                self::label('frames', (string) $metrics->frames),
                Style::of(size: Size::fixed(14)),
            ),
            $ui->text(
                self::label('tasks', (string) $metrics->tasks),
                Style::of(size: Size::fixed(12)),
            ),
            $ui->text(
                self::label('handles', (string) $metrics->handles),
                Style::of(size: Size::fixed(14)),
            ),
            $ui->text(
                self::label('events', (string) $metrics->events),
                Style::of(size: Size::fixed(14)),
            ),
        );

        $memRow = $ui->row(
            $ui->text(
                self::label('real', Metrics::memory($memory->memReal)),
                Style::of(size: Size::fixed(14)),
            ),
            $ui->text(
                self::label('zend', Metrics::memory($memory->memZend)),
                Style::of(size: Size::fixed(14)),
            ),
            $ui->text(
                self::label('peak/r', Metrics::memory($memory->memRealPeak)),
                Style::of(size: Size::fixed(16)),
            ),
            $ui->text(
                self::label('peak/z', Metrics::memory($memory->memZendPeak)),
                Style::of(size: Size::fixed(16)),
            ),
        );

        $scopeRow = $ui->text(
            Line::from(
                Span::styled(' scope:', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled((string) $scope->activeScopes, TextStyle::new()->fg(Color::brightWhite())),
                Span::styled(' run:', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(
                    $scope->currentRunId === '' ? 'none' : $scope->currentRunId,
                    TextStyle::new()->fg(Color::brightWhite()),
                ),
                Span::styled(' state:', TextStyle::new()->fg(Color::indexed(245))),
                Span::styled(
                    $scope->currentRunState === '' ? 'none' : $scope->currentRunState,
                    TextStyle::new()->fg(Color::brightWhite()),
                ),
            ),
        );

        $rows = [$header, $metricsRow, $memRow, $scopeRow];

        if ($reactorSlice->reactors !== []) {
            $rows[] = $ui->text(self::reactorLine($reactorSlice->reactors));
        }

        $latest = $trace->latest();
        if ($latest !== null) {
            $shortClass = substr(strrchr($latest->eventClass, '\\') ?: $latest->eventClass, 1);
            $rows[] = $ui->text(
                Line::from(
                    Span::styled(' last:', TextStyle::new()->fg(Color::indexed(245))),
                    Span::styled($shortClass, TextStyle::new()->fg(Color::brightCyan())),
                    Span::styled(" ({$latest->subscriberCount} subs)", TextStyle::new()->fg(Color::indexed(242))),
                ),
            );
        }

        return $ui->panel(
            '',
            $ui->column(...$rows),
            style: Style::of(
                size: Size::fill(),
                border: Border::Rounded,
                color: Color::indexed(208),
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
                ReactorState::Crashed => Color::brightRed(),
                ReactorState::Exhausted => Color::brightRed(),
                ReactorState::Cancelled => Color::indexed(242),
                default => Color::indexed(250),
            };

            $spans[] = Span::styled(" {$id}:", TextStyle::new()->fg(Color::indexed(250)));
            $spans[] = Span::styled($state->value, TextStyle::new()->fg($color));
        }

        return Line::from(...$spans);
    }
}
