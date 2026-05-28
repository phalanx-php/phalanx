<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Render;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use Phalanx\Theatron\Highlight\PhpHighlighter;
use Phalanx\Theatron\Highlight\TempestHighlighter;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style as TextStyle;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Size;
use Phalanx\Theatron\Tdom\Style;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;

final class MarkdownRenderer
{
    private MarkdownParser $parser;

    public function __construct()
    {
        $env = new Environment();
        $env->addExtension(new CommonMarkCoreExtension());
        $this->parser = new MarkdownParser($env);
    }

    public static function stripBlockSyntax(string $text): string
    {
        return (string) preg_replace(
            ['/^#{1,6}\s+/m', '/^```\w*\s*$/m', '/^[\-\*]\s+(?=\S)/m', '/^\d+\.\s+/m', '/^ {4}/m'],
            ['', '', '', '', ''],
            $text,
        );
    }

    public static function stripInlineSyntax(string $text): string
    {
        return (string) preg_replace(
            ['/\*\*(.+?)\*\*/', '/\*(.+?)\*/', '/`(.+?)`/', '/\[(.+?)\]\(.+?\)/'],
            ['$1', '$1', '$1', '$1'],
            $text,
        );
    }

    /** @return list<Renderable> */
    public function render(Ui $ui, string $markdown, int $wrapWidth, string $indent = '    '): array
    {
        $document = $this->parser->parse($markdown);

        $rows = [];
        $this->renderBlock($document, $indent, $wrapWidth, $rows);

        return array_map(
            static fn(Line $line): Renderable => $ui->text($line, style: Style::of(size: Size::fixed(1))),
            $rows,
        );
    }

    /** @param list<Line> &$rows */
    private function renderBlock(Node $node, string $indent, int $wrapWidth, array &$rows): void
    {
        foreach ($node->children() as $child) {
            if ($child instanceof Heading) {
                $this->renderHeading($child, $indent, $wrapWidth, $rows);
            } elseif ($child instanceof FencedCode || $child instanceof IndentedCode) {
                $this->renderCodeBlock($child, $indent, $wrapWidth, $rows);
            } elseif ($child instanceof Paragraph) {
                $this->renderParagraph($child, $indent, $wrapWidth, $rows);
            } elseif ($child instanceof ListBlock) {
                $this->renderList($child, $indent, $wrapWidth, $rows);
            } elseif ($child instanceof Document) {
                $this->renderBlock($child, $indent, $wrapWidth, $rows);
            }
        }
    }

    /** @param list<Line> &$rows */
    private function renderHeading(Heading $heading, string $indent, int $wrapWidth, array &$rows): void
    {
        $rows[] = Line::from(Span::plain(''));

        $text = $this->extractInlineText($heading);
        $style = TextStyle::new()->fg(Color::indexed(255))->bold();
        $lines = self::wrapIndented($text, $wrapWidth, $indent, $style);

        foreach ($lines as $line) {
            $rows[] = $line;
        }

        $rows[] = Line::from(Span::plain(''));
    }

    /** @param list<Line> &$rows */
    private function renderCodeBlock(FencedCode|IndentedCode $block, string $indent, int $wrapWidth, array &$rows): void
    {
        $code = $block->getLiteral();

        $code = rtrim($code, "\n");
        $lang = $block instanceof FencedCode ? ($block->getInfo() ?? '') : '';
        $lang = trim(explode(' ', $lang)[0]);

        $sepWidth = min((int) ($wrapWidth * 0.5), 40);
        $langLabel = $lang !== '' ? " {$lang} " : ' ';
        $remainSep = max(0, $sepWidth - mb_strlen($langLabel) - mb_strlen($indent));

        $rows[] = Line::from(Span::styled(
            $indent . '┈┈┈' . $langLabel . str_repeat('┈', $remainSep),
            TextStyle::new()->fg(Color::indexed(240)),
        ));

        $highlightedLines = $this->highlightCode($code, $lang);

        foreach ($highlightedLines as $hl) {
            $indentSpan = Span::plain($indent);
            $rows[] = Line::from($indentSpan, ...$hl->spans);
        }

        $rows[] = Line::from(Span::styled(
            $indent . str_repeat('┈', $sepWidth),
            TextStyle::new()->fg(Color::indexed(240)),
        ));
    }

    /** @param list<Line> &$rows */
    private function renderParagraph(Paragraph $para, string $indent, int $wrapWidth, array &$rows): void
    {
        $spans = $this->collectInlineSpans($para);

        if ($spans === []) {
            return;
        }

        $plainText = implode('', array_map(static fn(Span $s): string => $s->content, $spans));

        if (count($spans) === 1 && $spans[0]->style->equals(self::bodyStyle())) {
            $wrapped = self::wrapIndented($plainText, $wrapWidth, $indent, self::bodyStyle());
        } else {
            $wrapped = self::wrapStyledSpans($spans, $wrapWidth, $indent);
        }

        foreach ($wrapped as $line) {
            $rows[] = $line;
        }

        $rows[] = Line::from(Span::plain(''));
    }

    /** @param list<Line> &$rows */
    private function renderList(ListBlock $list, string $indent, int $wrapWidth, array &$rows): void
    {
        $data = $list->getListData();
        $ordered = $data->type === ListBlock::TYPE_ORDERED;
        $index = $data->start ?? 1;

        foreach ($list->children() as $item) {
            if (!$item instanceof ListItem) {
                continue;
            }

            $marker = $ordered ? "{$index}. " : "\u{2022} ";
            $index++;

            $this->renderListItem($item, $indent, $wrapWidth, $marker, $rows);
        }

        $rows[] = Line::from(Span::plain(''));
    }

    /** @param list<Line> &$rows */
    private function renderListItem(ListItem $item, string $indent, int $wrapWidth, string $marker, array &$rows): void
    {
        $spans = [];

        foreach ($item->children() as $child) {
            if ($child instanceof Paragraph) {
                $spans = [...$spans, ...$this->collectInlineSpans($child)];
            }
        }

        if ($spans === []) {
            $rows[] = Line::from(Span::styled($indent . $marker, self::bodyStyle()));

            return;
        }

        $markerLen = mb_strlen($marker);
        $firstPrefix = $indent . $marker;
        $contPrefix = $indent . str_repeat(' ', $markerLen);

        $wrapped = self::wrapStyledSpansWithPrefix($spans, $wrapWidth, $firstPrefix, $contPrefix);

        foreach ($wrapped as $line) {
            $rows[] = $line;
        }
    }

    /** @return list<Span> */
    private function collectInlineSpans(Node $node): array
    {
        $spans = [];

        foreach ($node->children() as $child) {
            if ($child instanceof Text) {
                $spans[] = Span::styled($child->getLiteral(), self::bodyStyle());
            } elseif ($child instanceof Code) {
                $spans[] = Span::styled(
                    " {$child->getLiteral()} ",
                    TextStyle::new()->fg(Color::indexed(215))->bg(Color::indexed(236)),
                );
            } elseif ($child instanceof Strong) {
                $text = $this->extractInlineText($child);
                $spans[] = Span::styled($text, TextStyle::new()->fg(Color::indexed(252))->bold());
            } elseif ($child instanceof Emphasis) {
                $text = $this->extractInlineText($child);
                $spans[] = Span::styled($text, TextStyle::new()->fg(Color::indexed(252))->italic());
            } elseif ($child instanceof Newline) {
                $spans[] = Span::plain(' ');
            } else {
                $spans = [...$spans, ...$this->collectInlineSpans($child)];
            }
        }

        return $spans;
    }

    private function extractInlineText(Node $node): string
    {
        $text = '';

        foreach ($node->children() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getLiteral();
            } elseif ($child instanceof Code) {
                $text .= $child->getLiteral();
            } else {
                $text .= $this->extractInlineText($child);
            }
        }

        return $text;
    }

    /** @return list<Line> */
    private function highlightCode(string $code, string $lang): array
    {
        if ($lang === 'php' || $lang === '') {
            return (new PhpHighlighter())->highlight($code);
        }

        return (new TempestHighlighter(language: $lang))->highlight($code);
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
     * @param list<Span> $spans
     * @return list<Line>
     */
    private static function wrapStyledSpans(array $spans, int $maxWidth, string $indent): array
    {
        return self::wrapStyledSpansWithPrefix($spans, $maxWidth, $indent, $indent);
    }

    /**
     * @param list<Span> $spans
     * @return list<Line>
     */
    private static function wrapStyledSpansWithPrefix(
        array $spans,
        int $maxWidth,
        string $firstPrefix,
        string $contPrefix,
    ): array {
        $firstLen = mb_strlen($firstPrefix);
        $contLen = mb_strlen($contPrefix);
        $lines = [];
        $currentSpans = [Span::plain($firstPrefix)];
        $currentWidth = 0;
        $lineWidth = max(10, $maxWidth - $firstLen);

        foreach ($spans as $span) {
            $words = preg_split('/(\s+)/', $span->content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

            foreach ($words as $fragment) {
                $fragLen = mb_strlen($fragment);
                $isSpace = trim($fragment) === '';

                if ($isSpace) {
                    if ($currentWidth + $fragLen <= $lineWidth) {
                        $currentSpans[] = Span::styled($fragment, $span->style);
                        $currentWidth += $fragLen;
                    }

                    continue;
                }

                if ($currentWidth + $fragLen > $lineWidth && $currentWidth > 0) {
                    $lines[] = Line::from(...$currentSpans);
                    $lineWidth = max(10, $maxWidth - $contLen);
                    $currentSpans = [Span::plain($contPrefix)];
                    $currentWidth = 0;
                }

                $currentSpans[] = Span::styled($fragment, $span->style);
                $currentWidth += $fragLen;
            }
        }

        if (count($currentSpans) > 1) {
            $lines[] = Line::from(...$currentSpans);
        }

        return $lines ?: [Line::from(Span::plain($firstPrefix))];
    }

    private static function bodyStyle(): TextStyle
    {
        return TextStyle::new()->fg(Color::indexed(252));
    }
}
