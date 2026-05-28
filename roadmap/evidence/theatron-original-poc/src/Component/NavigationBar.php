<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class NavigationBar implements StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable
    {
        $slice = $ctx->lens(NavigationBarSlice::class)->value;
        $spans = [Span::styled(' ', TextStyle::new())];

        foreach ($slice->items as $i => $item) {
            $isActive = $i === $slice->activeIndex;

            $style = $isActive
                ? TextStyle::new()->fg(Color::black())->bg(Color::brightCyan())->bold()
                : TextStyle::new()->fg(Color::indexed(245));

            $spans[] = Span::styled(" {$item->label} ", $style);
        }

        if ($slice->items === []) {
            $spans[] = Span::styled(' No panels registered', TextStyle::new()->fg(Color::indexed(242)));
        }

        return $ctx->ui->text(Line::from(...$spans), Style::of(size: Size::fill()));
    }
}
