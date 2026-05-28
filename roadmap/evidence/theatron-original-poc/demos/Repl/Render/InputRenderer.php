<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Render;

use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Demos\Repl\Slice\AgentStatusSlice;
use Phalanx\Theatron\Demos\Repl\Slice\InputSlice;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class InputRenderer
{
    private const array PULSE_COLORS = [242, 245, 248, 251, 254, 251, 248, 245];

    public function __construct(
        private(set) Lens $lens,
    ) {
    }

    public function renderStatusLine(Ui $ui): Renderable
    {
        $status = $this->lens->handle(AgentStatusSlice::class)->value;
        $input = $this->lens->handle(InputSlice::class)->value;

        if ($status->status === 'idle') {
            $spans = [
                Span::styled("  \u{039B} ", TextStyle::new()->fg(Color::indexed(242))),
                Span::styled($status->status, TextStyle::new()->fg(Color::indexed(242))),
            ];
        } else {
            $colorIndex = intdiv($status->spinnerFrame, 3) % count(self::PULSE_COLORS);
            $lambdaColor = self::PULSE_COLORS[$colorIndex];
            $spans = [
                Span::styled("  \u{039B} ", TextStyle::new()->fg(Color::indexed($lambdaColor))),
                Span::styled($status->status, TextStyle::new()->fg(Color::indexed(245))),
            ];
        }

        if ($input->queue !== []) {
            $count = count($input->queue);
            $queueLabel = $count === 1 ? '1 queued' : "{$count} queued";
            $spans[] = Span::styled("  \u{2502}  ", TextStyle::new()->fg(Color::indexed(238)));
            $spans[] = Span::styled($queueLabel, TextStyle::new()->fg(Color::indexed(242)));
        }

        return $ui->text(
            Line::from(...$spans),
            style: Style::of(size: Size::fixed(1)),
        );
    }

    public function renderInput(Ui $ui): Renderable
    {
        $input = $this->lens->handle(InputSlice::class)->value;

        return $ui->input(
            value: $input->text,
            prompt: '  +> ',
            cursor: mb_strlen($input->text),
            style: Style::of(size: Size::fixed(1)),
        );
    }

    public function renderStatusBar(Ui $ui, int $width, HotkeyContext $ctx): Renderable
    {
        $labels = [];
        $screen = $ctx->stack->top();

        if ($screen !== null) {
            foreach ($screen->bindings() as $binding) {
                if ($binding->label !== '') {
                    $labels[] = $binding->label;
                }
            }
        }

        foreach ($ctx->stack->globals->bindings() as $binding) {
            if ($binding->label !== '') {
                $labels[] = $binding->label;
            }
        }

        if ($ctx->stack->depth() > 1) {
            $labels[] = 'Esc:back';
        }

        $sep = TextStyle::new()->fg(Color::indexed(238));
        $keyStyle = TextStyle::new()->fg(Color::indexed(245));
        $actionStyle = TextStyle::new()->fg(Color::indexed(250));
        $pipe = Span::styled("  \u{2502}  ", $sep);

        $spans = [Span::styled('  ', $sep)];

        foreach ($labels as $i => $label) {
            if ($i > 0) {
                $spans[] = $pipe;
            }

            $colonPos = strpos($label, ':');

            if ($colonPos !== false) {
                $key = substr($label, 0, $colonPos);
                $action = substr($label, $colonPos + 1);
                $spans[] = Span::styled($key, $keyStyle);
                $spans[] = Span::styled(' ' . $action, $actionStyle);
            } else {
                $spans[] = Span::styled($label, $actionStyle);
            }
        }

        return $ui->text(
            Line::from(...$spans),
            style: Style::of(size: Size::fixed(1)),
        );
    }
}
