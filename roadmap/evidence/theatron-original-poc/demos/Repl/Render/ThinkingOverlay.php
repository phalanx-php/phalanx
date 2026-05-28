<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Render;

use Phalanx\Theatron\Demos\Repl\Slice\ConvoSlice;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class ThinkingOverlay
{
    public function __construct(
        private(set) Lens $lens,
    ) {
    }

    public function render(Ui $ui, int $width, int $height): Renderable
    {
        $convo = $this->lens->handle(ConvoSlice::class)->value;
        $wrapWidth = max(20, $width - 4);

        $content = $convo->activeTurn?->thinkingContent;

        if ($content === null || $content === '') {
            $lastThinking = $this->findLastThinking($convo);
            $content = $lastThinking ?? '(no thinking content available)';
        }

        $rows = [];

        $rows[] = $ui->text(Line::from(
            Span::styled('  ── Thinking ──────', TextStyle::new()->fg(Color::indexed(248))->bold()),
        ));
        $rows[] = $ui->text(Line::from(Span::plain('')));

        foreach (self::wrapIndented($content, $wrapWidth, '    ', TextStyle::new()->fg(Color::indexed(250))) as $wrapped) {
            $rows[] = $ui->text($wrapped);
        }

        $visible = array_slice($rows, 0, $height);

        return $ui->column(...$visible);
    }

    /** @return list<Line> */
    private static function wrapIndented(string $text, int $maxWidth, string $indent, TextStyle $style): array
    {
        $indentLen = mb_strlen($indent);
        $lineWidth = max(10, $maxWidth - $indentLen);
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $lineWidth) {
                $current .= ' ' . $word;
            } else {
                $lines[] = Line::from(Span::styled($indent . $current, $style));
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = Line::from(Span::styled($indent . $current, $style));
        }

        return $lines ?: [Line::from(Span::styled($indent, $style))];
    }

    private function findLastThinking(ConvoSlice $convo): ?string
    {
        for ($i = count($convo->history) - 1; $i >= 0; $i--) {
            if ($convo->history[$i]->thinkingContent !== null) {
                return $convo->history[$i]->thinkingContent;
            }
        }

        return null;
    }
}
