<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Render;

use Phalanx\Theatron\Demos\Repl\Slice\AgentStatusSlice;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class HeaderRenderer
{
    public function __construct(
        private(set) Lens $lens,
    ) {
    }

    public function render(Ui $ui, int $width): Renderable
    {
        $status = $this->lens->handle(AgentStatusSlice::class)->value;

        $dotColor = match ($status->status) {
            'thinking' => Color::indexed(248),
            'tool-use' => Color::indexed(252),
            default => Color::indexed(250),
        };

        $left = "  {$status->agentName} [{$status->role}]";
        $right = "● {$status->status}  ";
        $padding = max(0, $width - mb_strlen($left) - mb_strlen($right));

        return $ui->text(Line::from(
            Span::styled($left, TextStyle::new()->fg(Color::indexed(255))->bold()),
            Span::plain(str_repeat(' ', $padding)),
            Span::styled('●', TextStyle::new()->fg($dotColor)),
            Span::styled(" {$status->status}  ", TextStyle::new()->fg(Color::indexed(245))),
        ), style: Style::of(size: Size::fixed(1)));
    }
}
