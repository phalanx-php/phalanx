<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Component\StatefulContext;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class ModeIndicator implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $slice = $ctx->lens(InputModeSlice::class)->value;

        $label = match ($slice->mode) {
            InputMode::Normal => ' NORMAL ',
            InputMode::Insert => ' INSERT ',
        };

        $color = match ($slice->mode) {
            InputMode::Normal => Color::brightCyan(),
            InputMode::Insert => Color::brightGreen(),
        };

        $line = Line::from(
            Span::styled($label, TextStyle::new()->fg(Color::black())->bg($color)->bold()),
        );

        if ($slice->focusTarget !== null) {
            $line = Line::from(
                Span::styled($label, TextStyle::new()->fg(Color::black())->bg($color)->bold()),
                Span::styled(" {$slice->focusTarget}", TextStyle::new()->fg(Color::indexed(245))),
            );
        }

        return $ctx->ui->text($line, Style::of(size: Size::fill()));
    }
}
