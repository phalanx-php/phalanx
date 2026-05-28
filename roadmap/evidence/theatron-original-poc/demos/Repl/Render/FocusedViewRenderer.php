<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Render;

use Phalanx\Theatron\Demos\Repl\Slice\Exchange;
use Phalanx\Theatron\Demos\Repl\Slice\FocusedPane;
use Phalanx\Theatron\Demos\Repl\Slice\FocusedViewSlice;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class FocusedViewRenderer
{
    public function __construct(
        private(set) MarkdownRenderer $markdown,
    ) {
    }

    /** @return list<Renderable> */
    public function render(Ui $ui, Exchange $exchange, FocusedViewSlice $state, int $wrapWidth, int $availableHeight): array
    {
        $rows = [];
        $indent = '  ';
        $contentWidth = max(20, $wrapWidth - 4);

        $rows[] = self::row($ui, Line::from(Span::plain('')));
        $rows = [...$rows, ...$this->renderQuestionPane($ui, $exchange, $state, $contentWidth, $indent)];
        $rows[] = self::row($ui, Line::from(Span::plain('')));
        $rows = [...$rows, ...$this->renderAnswerPane($ui, $exchange, $state, $wrapWidth, $indent)];
        $rows[] = self::row($ui, Line::from(Span::plain('')));

        foreach ($exchange->toolCalls as $call) {
            $rows = [...$rows, ...ToolCallWidget::render($ui, $call, $wrapWidth, maxLines: null)];
        }

        $total = count($rows);

        if ($total <= $availableHeight) {
            return $rows;
        }

        $offset = min($state->scrollPosition, max(0, $total - $availableHeight));

        return array_values(array_slice($rows, $offset, $availableHeight));
    }

    /** @return list<Renderable> */
    private function renderQuestionPane(Ui $ui, Exchange $exchange, FocusedViewSlice $state, int $contentWidth, string $indent): array
    {
        $rows = [];
        $active = $state->activePane === FocusedPane::Question;
        $headerColor = $active ? Color::indexed(255) : Color::indexed(245);
        $sepChar = $active ? "\u{2504}" : "\u{2508}";

        $sepWidth = min($contentWidth, 50);
        $label = ' Question ';
        $remainSep = max(0, $sepWidth - mb_strlen($label) - 3);

        $rows[] = self::row($ui, Line::from(
            Span::styled("{$indent}{$sepChar}{$sepChar}{$sepChar}{$label}" . str_repeat($sepChar, $remainSep), TextStyle::new()->fg($headerColor)),
        ));

        $textStyle = TextStyle::new()->fg($active ? Color::indexed(252) : Color::indexed(245));
        $lines = self::wrapIndented($exchange->userMessage, $contentWidth, "{$indent}  ", $textStyle);

        if ($state->searchQuery !== null && $state->activePane === FocusedPane::Question) {
            $lines = self::highlightSearch($lines, $state->searchQuery, $state->searchMatchIndex);
        }

        foreach ($lines as $line) {
            $rows[] = self::row($ui, $line);
        }

        return $rows;
    }

    /** @return list<Renderable> */
    private function renderAnswerPane(Ui $ui, Exchange $exchange, FocusedViewSlice $state, int $wrapWidth, string $indent): array
    {
        $rows = [];
        $active = $state->activePane === FocusedPane::Answer;
        $headerColor = $active ? Color::indexed(255) : Color::indexed(245);
        $sepChar = $active ? "\u{2504}" : "\u{2508}";

        $contentWidth = max(20, $wrapWidth - 4);
        $sepWidth = min($contentWidth, 50);
        $label = ' Answer ';
        $remainSep = max(0, $sepWidth - mb_strlen($label) - 3);

        $rows[] = self::row($ui, Line::from(
            Span::styled("{$indent}{$sepChar}{$sepChar}{$sepChar}{$label}" . str_repeat($sepChar, $remainSep), TextStyle::new()->fg($headerColor)),
        ));

        foreach ($this->markdown->render($ui, $exchange->assistantResponse, $wrapWidth) as $rendered) {
            $rows[] = $rendered;
        }

        return $rows;
    }

    /**
     * @param list<Line> $lines
     * @return list<Line>
     */
    private static function highlightSearch(array $lines, string $query, int $currentMatch): array
    {
        if ($query === '') {
            return $lines;
        }

        $matchIndex = 0;
        $highlighted = [];

        foreach ($lines as $line) {
            $newSpans = [];

            foreach ($line->spans as $span) {
                $content = $span->content;
                $offset = 0;

                while (($pos = mb_stripos($content, $query, $offset)) !== false) {
                    if ($pos > $offset) {
                        $newSpans[] = Span::styled(mb_substr($content, $offset, $pos - $offset), $span->style);
                    }

                    $matched = mb_substr($content, $pos, mb_strlen($query));
                    $isCurrent = $matchIndex === $currentMatch;
                    $matchIndex++;

                    $matchStyle = $isCurrent
                        ? TextStyle::new()->fg(Color::indexed(232))->bg(Color::indexed(248))
                        : TextStyle::new()->fg(Color::indexed(232))->bg(Color::indexed(242));

                    $newSpans[] = Span::styled($matched, $matchStyle);
                    $offset = $pos + mb_strlen($query);
                }

                if ($offset < mb_strlen($content)) {
                    $newSpans[] = Span::styled(mb_substr($content, $offset), $span->style);
                }
            }

            $highlighted[] = $newSpans !== [] ? Line::from(...$newSpans) : $line;
        }

        return $highlighted;
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

    private static function row(Ui $ui, Line $line): Renderable
    {
        return $ui->text($line, style: Style::of(size: Size::fixed(1)));
    }
}
