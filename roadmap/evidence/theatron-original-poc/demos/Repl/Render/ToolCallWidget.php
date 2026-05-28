<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Render;

use Phalanx\Theatron\Demos\Repl\Slice\ToolCallSummary;
use Phalanx\Theatron\Highlight\TempestHighlighter;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

class ToolCallWidget
{
    /** @return list<Renderable> */
    public static function render(Ui $ui, ToolCallSummary $call, int $wrapWidth, ?int $maxLines = 12): array
    {
        $rows = [];
        $rows[] = self::headerRow($ui, $call, $wrapWidth);

        if ($call->expanded && $call->resultContent !== null) {
            $rows = [...$rows, ...self::renderResult($ui, $call, $wrapWidth, $maxLines)];
        }

        return $rows;
    }

    private static function headerRow(Ui $ui, ToolCallSummary $call, int $wrapWidth): Renderable
    {
        $indicator = self::statusIndicator($call->status);
        $args = $call->argumentsSummary !== '' ? "({$call->argumentsSummary})" : '';
        $name = "  {$indicator} {$call->toolName}{$args}";

        $statusLabel = match ($call->status) {
            'ok' => ' ok',
            'error' => ' error',
            'running' => ' ...',
            default => '',
        };

        $statusColor = match ($call->status) {
            'ok' => Color::indexed(250),
            'error' => Color::indexed(245),
            'running' => Color::indexed(248),
            default => Color::indexed(245),
        };

        $nameLen = mb_strlen($name);
        $statusLen = mb_strlen($statusLabel);
        $padLen = max(0, $wrapWidth - $nameLen - $statusLen - 2);

        return self::row($ui, Line::from(
            Span::styled($name, TextStyle::new()->fg(Color::indexed(252))),
            Span::plain(str_repeat(' ', $padLen)),
            Span::styled($statusLabel, TextStyle::new()->fg($statusColor)),
        ));
    }

    private static function statusIndicator(?string $status): string
    {
        return match ($status) {
            'ok' => "\u{2713}",
            'error' => "\u{2717}",
            'running' => "\u{25CF}",
            default => "\u{25CB}",
        };
    }

    /** @return list<Renderable> */
    private static function renderResult(Ui $ui, ToolCallSummary $call, int $wrapWidth, ?int $maxLines): array
    {
        $rows = [];
        $indent = '    ';
        $contentWidth = max(20, $wrapWidth - mb_strlen($indent) - 2);
        $sepWidth = min($contentWidth, 40);

        $rows[] = self::row($ui, Line::from(
            Span::styled($indent . str_repeat("\u{250A}", $sepWidth), TextStyle::new()->fg(Color::indexed(238))),
        ));

        $lines = match ($call->resultType) {
            'diff' => self::renderDiff($call->resultContent, $indent),
            'search' => self::renderSearch($call->resultContent, $indent, $contentWidth),
            'code' => self::renderCode($call->resultContent, $indent),
            'json' => self::renderJson($call->resultContent, $indent, $contentWidth),
            default => self::renderText($call->resultContent, $indent, $contentWidth),
        };

        $visible = $maxLines !== null ? array_slice($lines, 0, $maxLines) : $lines;

        foreach ($visible as $line) {
            $rows[] = self::row($ui, $line);
        }

        if ($maxLines !== null && count($lines) > $maxLines) {
            $remaining = count($lines) - $maxLines;
            $rows[] = self::row($ui, Line::from(
                Span::styled("{$indent}... {$remaining} more lines", TextStyle::new()->fg(Color::indexed(242))),
            ));
        }

        $rows[] = self::row($ui, Line::from(
            Span::styled($indent . str_repeat("\u{250A}", $sepWidth), TextStyle::new()->fg(Color::indexed(238))),
        ));

        return $rows;
    }

    /** @return list<Line> */
    private static function renderDiff(string $content, string $indent): array
    {
        $lines = [];

        foreach (explode("\n", $content) as $raw) {
            if (str_starts_with($raw, '+')) {
                $lines[] = Line::from(Span::styled($indent . $raw, TextStyle::new()->fg(Color::indexed(250))));
            } elseif (str_starts_with($raw, '-')) {
                $lines[] = Line::from(Span::styled($indent . $raw, TextStyle::new()->fg(Color::indexed(245))));
            } elseif (str_starts_with($raw, '@@')) {
                $lines[] = Line::from(Span::styled($indent . $raw, TextStyle::new()->fg(Color::indexed(252))));
            } else {
                $lines[] = Line::from(Span::styled($indent . $raw, TextStyle::new()->fg(Color::indexed(245))));
            }
        }

        return $lines;
    }

    /** @return list<Line> */
    private static function renderSearch(string $content, string $indent, int $maxWidth): array
    {
        $lines = [];

        foreach (explode("\n", $content) as $raw) {
            if (preg_match('/^(\d+)[:|](.*)$/', $raw, $m)) {
                $lineNum = $m[1];
                $text = mb_substr($m[2], 0, $maxWidth - mb_strlen($lineNum) - 2);
                $lines[] = Line::from(
                    Span::styled("{$indent}{$lineNum}:", TextStyle::new()->fg(Color::indexed(242))),
                    Span::styled(" {$text}", TextStyle::new()->fg(Color::indexed(252))),
                );
            } elseif ($raw !== '' && !str_starts_with($raw, ' ')) {
                $lines[] = Line::from(
                    Span::styled("{$indent}{$raw}", TextStyle::new()->fg(Color::indexed(252))->bold()),
                );
            } else {
                $display = mb_substr($raw, 0, $maxWidth);
                $lines[] = Line::from(Span::styled("{$indent}{$display}", TextStyle::new()->fg(Color::indexed(250))));
            }
        }

        return $lines;
    }

    /** @return list<Line> */
    private static function renderCode(string $content, string $indent): array
    {
        return self::highlightWith('php', $content, $indent);
    }

    /** @return list<Line> */
    private static function renderJson(string $content, string $indent, int $maxWidth): array
    {
        $decoded = json_decode($content, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return self::renderText($content, $indent, $maxWidth);
        }

        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::highlightWith('json', $pretty, $indent);
    }

    /** @return list<Line> */
    private static function highlightWith(string $language, string $content, string $indent): array
    {
        $highlighted = (new TempestHighlighter($language))->highlight(rtrim($content, "\n"));
        $lines = [];

        foreach ($highlighted as $hl) {
            $lines[] = Line::from(Span::plain($indent), ...$hl->spans);
        }

        return $lines;
    }

    /** @return list<Line> */
    private static function renderText(string $content, string $indent, int $maxWidth): array
    {
        $lines = [];
        $words = preg_split('/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);
        $current = '';

        foreach ($words as $word) {
            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $maxWidth) {
                $current .= ' ' . $word;
            } else {
                $lines[] = Line::from(Span::styled($indent . $current, TextStyle::new()->fg(Color::indexed(250))));
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = Line::from(Span::styled($indent . $current, TextStyle::new()->fg(Color::indexed(250))));
        }

        return $lines ?: [Line::from(Span::plain($indent))];
    }

    private static function row(Ui $ui, Line $line): Renderable
    {
        return $ui->text($line, style: Style::of(size: Size::fixed(1)));
    }
}
