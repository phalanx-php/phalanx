<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Render;

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
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use Phalanx\Theatron\Layout\Size;
use Phalanx\Theatron\Style\Color;
use Phalanx\Theatron\Style\Style;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style as TdomStyle;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;
use function Phalanx\Theatron\Ui\text;

class MarkdownRenderer
{
    private MarkdownParser $parser;
    private CodeHighlighter $highlighter;

    public function __construct()
    {
        $env = new Environment();
        $env->addExtension(new CommonMarkCoreExtension());
        $this->parser = new MarkdownParser($env);
        $this->highlighter = new CodeHighlighter();
    }

    public static function stripSyntax(string $text): string
    {
        return (string) preg_replace(
            [
                '/^#{1,6}\s+/m',
                '/^```\w*\s*$/m',
                '/^[\-\*]\s+(?=\S)/m',
                '/^\d+\.\s+/m',
                '/\*\*(.+?)\*\*/',
                '/\*(.+?)\*/',
                '/`(.+?)`/',
            ],
            ['', '', '', '', '$1', '$1', '$1'],
            $text,
        );
    }

    /** @return list<Renderable> */
    public function render(string $markdown, int $wrapWidth, string $indent = '    '): array
    {
        $rows = [];
        $this->renderChildren($this->parser->parse($markdown), $indent, $wrapWidth, $rows);

        return $this->renderBodyRows($rows);
    }

    /**
     * @param list<Line> $rows
     * @return list<Renderable>
     */
    public function renderBodyRows(array $rows): array
    {
        return array_map(
            static fn(Line $line): Renderable => text($line, TdomStyle::of(size: Size::fixed(1))),
            $rows,
        );
    }

    /** @return list<Line> */
    private static function wrap(string $text, int $maxWidth, string $indent, Style $style): array
    {
        return self::wrapSpans([Span::styled($text, $style)], $maxWidth, $indent, $indent);
    }

    /**
     * @param list<Span> $spans
     * @return list<Line>
     */
    private static function wrapSpans(array $spans, int $maxWidth, string $firstPrefix, string $contPrefix): array
    {
        $lines = [];
        $current = [Span::plain($firstPrefix)];
        $width = 0;
        $lineWidth = max(10, $maxWidth - mb_strlen($firstPrefix));

        foreach ($spans as $span) {
            $parts = preg_split(
                '/(\s+)/',
                $span->content,
                -1,
                PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
            ) ?: [];

            foreach ($parts as $part) {
                $len = mb_strlen($part);

                if ($width + $len > $lineWidth && $width > 0) {
                    $lines[] = Line::from(...$current);
                    $current = [Span::plain($contPrefix)];
                    $width = 0;
                    $lineWidth = max(10, $maxWidth - mb_strlen($contPrefix));
                }

                $current[] = Span::styled($part, $span->style);
                $width += $len;
            }
        }

        if (count($current) > 1) {
            $lines[] = Line::from(...$current);
        }

        return $lines ?: [Line::from(Span::plain($firstPrefix))];
    }

    private static function bodyStyle(): Style
    {
        return Style::new()->fg(Color::indexed(252));
    }

    /** @param list<Line> &$rows */
    private function renderChildren(Node $node, string $indent, int $wrapWidth, array &$rows): void
    {
        foreach ($node->children() as $child) {
            if ($child instanceof Heading) {
                $rows[] = Line::plain('');
                $headingStyle = Style::new()->fg(Color::indexed(255))->bold();

                foreach (self::wrap($this->text($child), $wrapWidth, $indent, $headingStyle) as $line) {
                    $rows[] = $line;
                }
                $rows[] = Line::plain('');
            } elseif ($child instanceof FencedCode || $child instanceof IndentedCode) {
                $this->renderCode($child, $indent, $wrapWidth, $rows);
            } elseif ($child instanceof Paragraph) {
                foreach (self::wrapSpans($this->spans($child), $wrapWidth, $indent, $indent) as $line) {
                    $rows[] = $line;
                }
                $rows[] = Line::plain('');
            } elseif ($child instanceof ListBlock) {
                $this->renderList($child, $indent, $wrapWidth, $rows);
            } else {
                $this->renderChildren($child, $indent, $wrapWidth, $rows);
            }
        }
    }

    /** @param list<Line> &$rows */
    private function renderCode(FencedCode|IndentedCode $block, string $indent, int $wrapWidth, array &$rows): void
    {
        $language = $block instanceof FencedCode ? trim(explode(' ', $block->getInfo() ?? '')[0]) : '';
        $label = $language !== '' ? " {$language} " : ' ';
        $sepWidth = min((int) ($wrapWidth * 0.5), 40);
        $labelWidth = mb_strlen($indent . $label);
        $rule = $indent . '┈┈┈' . $label . str_repeat('┈', max(1, $sepWidth - $labelWidth - 3));
        $rows[] = Line::from(Span::styled($rule, Style::new()->fg(Color::indexed(240))));

        foreach ($this->highlighter->highlight(rtrim($block->getLiteral(), "\n"), $language) as $line) {
            $rows[] = Line::from(Span::plain($indent), ...$line->spans);
        }

        $rows[] = Line::from(Span::styled($indent . str_repeat('┈', $sepWidth), Style::new()->fg(Color::indexed(240))));
    }

    /** @param list<Line> &$rows */
    private function renderList(ListBlock $list, string $indent, int $wrapWidth, array &$rows): void
    {
        $ordered = $list->getListData()->type === ListBlock::TYPE_ORDERED;
        $index = $list->getListData()->start ?? 1;

        foreach ($list->children() as $item) {
            if (!$item instanceof ListItem) {
                continue;
            }

            $marker = $ordered ? "{$index}. " : '• ';
            $index++;
            $spans = [];

            foreach ($item->children() as $child) {
                if ($child instanceof Paragraph) {
                    $spans = [...$spans, ...$this->spans($child)];
                }
            }

            $firstPrefix = $indent . $marker;
            $contPrefix = $indent . str_repeat(' ', mb_strlen($marker));

            foreach (self::wrapSpans($spans, $wrapWidth, $firstPrefix, $contPrefix) as $line) {
                $rows[] = $line;
            }
        }

        $rows[] = Line::plain('');
    }

    /** @return list<Span> */
    private function spans(Node $node): array
    {
        $spans = [];

        foreach ($node->children() as $child) {
            if ($child instanceof Text) {
                $spans[] = Span::styled($child->getLiteral(), self::bodyStyle());
            } elseif ($child instanceof Code) {
                $codeStyle = Style::new()
                    ->fg(Color::indexed(215))
                    ->bg(Color::indexed(236));
                $spans[] = Span::styled(' ' . $child->getLiteral() . ' ', $codeStyle);
            } elseif ($child instanceof Strong) {
                $spans[] = Span::styled($this->text($child), Style::new()->fg(Color::indexed(252))->bold());
            } elseif ($child instanceof Emphasis) {
                $spans[] = Span::styled($this->text($child), Style::new()->fg(Color::indexed(252))->italic());
            } elseif ($child instanceof Newline) {
                $spans[] = Span::plain(' ');
            } else {
                $spans = [...$spans, ...$this->spans($child)];
            }
        }

        return $spans;
    }

    private function text(Node $node): string
    {
        $text = '';

        foreach ($node->children() as $child) {
            $text .= $child instanceof Text || $child instanceof Code
                ? $child->getLiteral()
                : $this->text($child);
        }

        return $text;
    }
}
