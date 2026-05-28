<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Render;

use Phalanx\Theatron\Demos\Repl\Slice\ActiveTurn;
use Phalanx\Theatron\Demos\Repl\Slice\ConvoSlice;
use Phalanx\Theatron\Demos\Repl\Slice\Exchange;
use Phalanx\Theatron\Demos\Repl\Slice\ExchangeSummary;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Element\ColumnElement;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class ConversationRenderer
{
    private(set) MarkdownRenderer $markdown;

    public function __construct(
        private(set) Lens $lens,
    ) {
        $this->markdown = new MarkdownRenderer();
    }

    public function render(Ui $ui, int $width, int $availableHeight): Renderable
    {
        $convo = $this->lens->handle(ConvoSlice::class)->value;
        $wrapWidth = max(20, $width - 2);

        $header = self::row($ui, Line::from(
            Span::styled("  \u{039B}\u{032C} ", TextStyle::new()->fg(Color::indexed(250))),
            Span::styled('Theatron', TextStyle::new()->fg(Color::indexed(250))),
            Span::styled("  \u{2502}  ", TextStyle::new()->fg(Color::indexed(238))),
            Span::styled('Powered by Phalanx PHP', TextStyle::new()->fg(Color::indexed(242))),
        ));

        $bodyHeight = max(1, $availableHeight - 1);

        if ($convo->expandedIndex !== null) {
            $rows = $this->renderExpanded($ui, $convo, $wrapWidth, $bodyHeight);
        } else {
            $rows = $this->renderNormal($ui, $convo, $wrapWidth, $bodyHeight);
        }

        return new ColumnElement([$header, ...$rows], Style::of(size: Size::fill()));
    }

    /** @return list<Renderable> */
    private function renderNormal(Ui $ui, ConvoSlice $convo, int $wrapWidth, int $availableHeight): array
    {
        $rows = [];
        $historyCount = count($convo->history);

        $compactEnd = ($convo->activeTurn !== null) ? $historyCount : $historyCount - 1;

        for ($i = 0; $i < $compactEnd; $i++) {
            $highlighted = $convo->scrollOffset > 0
                && $i === $historyCount - $convo->scrollOffset;
            self::renderCompactSummary($ui, $convo->history[$i], $wrapWidth, $highlighted, $rows);
        }

        if ($convo->activeTurn === null && $convo->lastExchange !== null) {
            $this->renderFullExchange($ui, $convo->lastExchange, $wrapWidth, $rows);
        }

        if ($convo->activeTurn !== null) {
            $this->renderActiveTurn($ui, $convo->activeTurn, $wrapWidth, $convo->showThinking, $rows);
        }

        if ($rows === []) {
            $rows[] = self::row($ui, Line::from(
                Span::styled('  Type a message to begin.', TextStyle::new()->fg(Color::indexed(242))),
            ));
        }

        return self::viewport($rows, $availableHeight, $convo->scrollOffset === 0, $ui);
    }

    /** @return list<Renderable> */
    private function renderExpanded(Ui $ui, ConvoSlice $convo, int $wrapWidth, int $availableHeight): array
    {
        if ($convo->lastExchange === null) {
            return [self::row($ui, Line::from(Span::styled('  Loading...', TextStyle::new()->fg(Color::indexed(242)))))];
        }

        $rows = [];

        $this->renderFullExchange($ui, $convo->lastExchange, $wrapWidth, $rows);

        $rows[] = self::row($ui, Line::from(Span::plain('')));
        $rows[] = self::row($ui, Line::from(
            Span::styled('  [Esc to close]', TextStyle::new()->fg(Color::indexed(242))),
        ));

        return array_slice($rows, 0, $availableHeight);
    }

    /** @param list<Renderable> &$rows */
    private static function renderCompactSummary(Ui $ui, ExchangeSummary $summary, int $wrapWidth, bool $highlighted, array &$rows): void
    {
        $userStyle = $highlighted
            ? TextStyle::new()->fg(Color::indexed(255))
            : TextStyle::new()->fg(Color::indexed(250));
        $assistantStyle = $highlighted
            ? TextStyle::new()->fg(Color::indexed(250))
            : TextStyle::new()->fg(Color::indexed(245));
        $separatorColor = $highlighted ? Color::indexed(242) : Color::indexed(238);

        $userLines = self::wrapIndented($summary->userPreview, $wrapWidth, '  > ', $userStyle);
        $stripped = MarkdownRenderer::stripInlineSyntax(MarkdownRenderer::stripBlockSyntax($summary->assistantPreview));
        $assistantLines = self::wrapIndented($stripped, $wrapWidth, '    ', $assistantStyle);

        foreach (array_slice($userLines, 0, 2) as $line) {
            $rows[] = self::row($ui, $line);
        }

        $sepWidth = (int) ($wrapWidth * 0.6);
        $rows[] = self::row($ui, Line::from(
            Span::styled('  ' . str_repeat("\u{2500}", $sepWidth), TextStyle::new()->fg($separatorColor)),
        ));

        foreach (array_slice($assistantLines, 0, 2) as $line) {
            $rows[] = self::row($ui, $line);
        }

        if ($summary->toolCallCount > 0) {
            $toolLabel = $summary->toolCallCount === 1 ? '1 tool call' : "{$summary->toolCallCount} tool calls";
            $rows[] = self::row($ui, Line::from(
                Span::styled("    [{$toolLabel}]", TextStyle::new()->fg(Color::indexed(242))),
            ));
        }

        $rows[] = self::row($ui, Line::from(Span::plain('')));
    }

    /** @param list<Renderable> &$rows */
    private function renderFullExchange(Ui $ui, Exchange $exchange, int $wrapWidth, array &$rows): void
    {
        $rows[] = self::row($ui, Line::from(Span::plain('')));

        $humanLabel = Span::styled('  you: ', TextStyle::new()->fg(Color::indexed(255))->bold());
        $firstUserLine = mb_substr($exchange->userMessage, 0, $wrapWidth - 10);
        $remainder = mb_strlen($exchange->userMessage) > ($wrapWidth - 10)
            ? mb_substr($exchange->userMessage, $wrapWidth - 10)
            : '';

        $rows[] = self::row($ui, Line::from(
            $humanLabel,
            Span::styled($firstUserLine, TextStyle::new()->fg(Color::indexed(252))),
        ));

        if ($remainder !== '') {
            foreach (self::wrapIndented($remainder, $wrapWidth, '         ', TextStyle::new()->fg(Color::indexed(252))) as $line) {
                $rows[] = self::row($ui, $line);
            }
        }

        $sepWidth = min(24, (int) ($wrapWidth * 0.2));
        $rows[] = self::row($ui, Line::from(
            Span::styled('  ' . str_repeat("\u{2500}", $sepWidth), TextStyle::new()->fg(Color::indexed(236))),
        ));

        $rows[] = self::row($ui, Line::from(
            Span::styled('  pericles:', TextStyle::new()->fg(Color::indexed(252))->bold()),
        ));

        foreach ($this->markdown->render($ui, $exchange->assistantResponse, $wrapWidth, '    ') as $rendered) {
            $rows[] = $rendered;
        }

        foreach ($exchange->toolCalls as $call) {
            $rows = [...$rows, ...ToolCallWidget::render($ui, $call, $wrapWidth)];
        }
    }

    /** @param list<Renderable> &$rows */
    private function renderActiveTurn(Ui $ui, ActiveTurn $turn, int $wrapWidth, bool $showThinking, array &$rows): void
    {
        $rows[] = self::row($ui, Line::from(Span::plain('')));

        $humanLabel = Span::styled('  you: ', TextStyle::new()->fg(Color::indexed(255))->bold());
        $firstUserLine = mb_substr($turn->userMessage, 0, $wrapWidth - 10);
        $remainder = mb_strlen($turn->userMessage) > ($wrapWidth - 10)
            ? mb_substr($turn->userMessage, $wrapWidth - 10)
            : '';

        $rows[] = self::row($ui, Line::from(
            $humanLabel,
            Span::styled($firstUserLine, TextStyle::new()->fg(Color::indexed(252))),
        ));

        if ($remainder !== '') {
            foreach (self::wrapIndented($remainder, $wrapWidth, '         ', TextStyle::new()->fg(Color::indexed(252))) as $line) {
                $rows[] = self::row($ui, $line);
            }
        }

        $rows[] = self::row($ui, Line::from(Span::plain('')));

        if ($showThinking && $turn->thinkingContent !== null) {
            self::renderThinkingPanel($ui, $turn->thinkingContent, $wrapWidth, $rows);
        }

        if ($turn->streamedText === '' && $turn->toolCalls === []) {
            return;
        }

        if ($turn->streamedText !== '') {
            $rows[] = self::row($ui, Line::from(
                Span::styled('  pericles:', TextStyle::new()->fg(Color::indexed(252))->bold()),
            ));

            $lastNewline = strrpos($turn->streamedText, "\n");

            if ($lastNewline !== false) {
                $completePortion = substr($turn->streamedText, 0, $lastNewline + 1);
                $trailing = substr($turn->streamedText, $lastNewline + 1);
            } else {
                $completePortion = '';
                $trailing = $turn->streamedText;
            }

            if ($completePortion !== '') {
                foreach ($this->markdown->render($ui, $completePortion, $wrapWidth, '    ') as $rendered) {
                    $rows[] = $rendered;
                }
            }

            if ($trailing !== '') {
                foreach ($this->markdown->render($ui, $trailing, $wrapWidth, '    ') as $rendered) {
                    $rows[] = $rendered;
                }
            }
        }

        foreach ($turn->toolCalls as $call) {
            $rows = [...$rows, ...ToolCallWidget::render($ui, $call, $wrapWidth)];
        }
    }

    private const int THINKING_MAX_LINES = 4;

    /** @param list<Renderable> &$rows */
    private static function renderThinkingPanel(Ui $ui, string $content, int $wrapWidth, array &$rows): void
    {
        $borderStyle = TextStyle::new()->fg(Color::indexed(240));
        $contentStyle = TextStyle::new()->fg(Color::indexed(245));
        $panelWidth = max(20, $wrapWidth - 6);
        $contentWidth = max(10, $panelWidth - 6);

        $wrapped = self::wrapPlain($content, $contentWidth);
        $visible = array_slice($wrapped, -self::THINKING_MAX_LINES);

        $headerBar = str_repeat("\u{2500}", max(1, $panelWidth - 14));
        $rows[] = self::row($ui, Line::from(
            Span::styled("  \u{256D}\u{2500} thinking {$headerBar}", $borderStyle),
        ));

        foreach ($visible as $line) {
            $padded = str_pad($line, $contentWidth);
            $rows[] = self::row($ui, Line::from(
                Span::styled("  \u{2502} ", $borderStyle),
                Span::styled($padded, $contentStyle),
            ));
        }

        $footerBar = str_repeat("\u{2500}", max(1, $panelWidth - 5));
        $rows[] = self::row($ui, Line::from(
            Span::styled("  \u{2570}{$footerBar}", $borderStyle),
        ));
    }

    /** @return list<string> */
    private static function wrapPlain(string $text, int $maxWidth): array
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $maxWidth) {
                $current .= ' ' . $word;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }

    private static function row(Ui $ui, Line $line): Renderable
    {
        return $ui->text($line, style: Style::of(size: Size::fixed(1)));
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

    /**
     * @param list<Renderable> $rows
     * @return list<Renderable>
     */
    private static function viewport(array $rows, int $maxRows, bool $stickToBottom, Ui $ui): array
    {
        if (count($rows) <= $maxRows) {
            if ($stickToBottom && $rows !== []) {
                $spacerCount = $maxRows - count($rows);
                $spacers = [];

                for ($i = 0; $i < $spacerCount; $i++) {
                    $spacers[] = self::row($ui, Line::from(Span::plain('')));
                }

                return [...$spacers, ...$rows];
            }

            return $rows;
        }

        if ($stickToBottom) {
            return array_values(array_slice($rows, -$maxRows));
        }

        return array_slice($rows, 0, $maxRows);
    }
}
