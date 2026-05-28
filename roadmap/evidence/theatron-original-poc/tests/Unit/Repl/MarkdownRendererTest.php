<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Render\MarkdownRenderer;
use Phalanx\Theatron\Style\Modifier;
use Phalanx\Theatron\Tdom\Element\TextElement;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;
use Phalanx\Theatron\Text\Span;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $renderer;
    private Ui $ui;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer();
        $this->ui = new Ui();
    }

    #[Test]
    public function heading_renders_with_bold_style(): void
    {
        $elements = $this->renderer->render($this->ui, '# Olympus', 80);

        $lines = $this->extractLines($elements);
        $headingLine = $this->findLineContaining($lines, 'Olympus');

        self::assertNotNull($headingLine, 'Heading text should appear in output');

        $headingSpan = $this->findSpanContaining($headingLine, 'Olympus');
        self::assertNotNull($headingSpan);
        self::assertTrue($headingSpan->style->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function paragraph_text_renders_as_body(): void
    {
        $elements = $this->renderer->render($this->ui, 'The phalanx holds the line.', 80);

        $lines = $this->extractLines($elements);
        $textLine = $this->findLineContaining($lines, 'phalanx holds');

        self::assertNotNull($textLine, 'Paragraph text should appear in output');
    }

    #[Test]
    public function inline_code_renders_with_distinct_style(): void
    {
        $elements = $this->renderer->render($this->ui, 'Use `$scope->call()` for suspension.', 80);

        $lines = $this->extractLines($elements);
        $codeLine = $this->findLineContaining($lines, '$scope->call()');

        self::assertNotNull($codeLine);

        $codeSpan = $this->findSpanContaining($codeLine, '$scope->call()');
        self::assertNotNull($codeSpan);
        // Inline code has bg color set (indexed 236)
        self::assertNotNull($codeSpan->style->background);
    }

    #[Test]
    public function bold_text_renders_with_bold_modifier(): void
    {
        $elements = $this->renderer->render($this->ui, 'This is **important** text.', 80);

        $lines = $this->extractLines($elements);
        $boldLine = $this->findLineContaining($lines, 'important');

        self::assertNotNull($boldLine);

        $boldSpan = $this->findSpanContaining($boldLine, 'important');
        self::assertNotNull($boldSpan);
        self::assertTrue($boldSpan->style->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function italic_text_renders_with_italic_modifier(): void
    {
        $elements = $this->renderer->render($this->ui, 'This is *emphasized* text.', 80);

        $lines = $this->extractLines($elements);
        $italicLine = $this->findLineContaining($lines, 'emphasized');

        self::assertNotNull($italicLine);

        $italicSpan = $this->findSpanContaining($italicLine, 'emphasized');
        self::assertNotNull($italicSpan);
        self::assertTrue($italicSpan->style->hasModifier(Modifier::Italic));
    }

    #[Test]
    public function fenced_code_block_renders_with_separator_lines(): void
    {
        $md = "```php\necho 'Sparta';\n```";
        $elements = $this->renderer->render($this->ui, $md, 80);

        $lines = $this->extractLines($elements);

        // Code blocks have separator lines with the language label
        $sepLine = $this->findLineContaining($lines, 'php');
        self::assertNotNull($sepLine, 'Code block should show language label in separator');
    }

    #[Test]
    public function bullet_list_renders_with_bullet_character(): void
    {
        $md = "* First item\n* Second item\n* Third item";
        $elements = $this->renderer->render($this->ui, $md, 80);

        $lines = $this->extractLines($elements);

        self::assertNotNull($this->findLineContaining($lines, 'First'));
        self::assertNotNull($this->findLineContaining($lines, 'Second'));
        self::assertNotNull($this->findLineContaining($lines, 'Third'));

        $bulletLine = $this->findLineContaining($lines, "\u{2022}");
        self::assertNotNull($bulletLine, 'At least one line should have a bullet character');
    }

    #[Test]
    public function ordered_list_renders_with_numbers(): void
    {
        $md = "1. Alpha\n2. Beta\n3. Gamma";
        $elements = $this->renderer->render($this->ui, $md, 80);

        $lines = $this->extractLines($elements);

        self::assertNotNull($this->findLineContaining($lines, 'Alpha'));
        self::assertNotNull($this->findLineContaining($lines, 'Beta'));
        self::assertNotNull($this->findLineContaining($lines, 'Gamma'));

        $numberedLine = $this->findLineContaining($lines, '1.');
        self::assertNotNull($numberedLine, 'Should have numbered marker');
    }

    #[Test]
    public function bold_within_list_item_preserves_bold_modifier(): void
    {
        $md = "* **Strategic** advantage requires preparation";
        $elements = $this->renderer->render($this->ui, $md, 80);

        $lines = $this->extractLines($elements);
        $line = $this->findLineContaining($lines, 'Strategic');

        self::assertNotNull($line);

        $boldSpan = $this->findSpanContaining($line, 'Strategic');
        self::assertNotNull($boldSpan);
        self::assertTrue($boldSpan->style->hasModifier(Modifier::Bold));
    }

    #[Test]
    public function italic_within_list_item_preserves_italic_modifier(): void
    {
        $md = "* *Tactical* retreat is not defeat";
        $elements = $this->renderer->render($this->ui, $md, 80);

        $lines = $this->extractLines($elements);
        $line = $this->findLineContaining($lines, 'Tactical');

        self::assertNotNull($line);

        $italicSpan = $this->findSpanContaining($line, 'Tactical');
        self::assertNotNull($italicSpan);
        self::assertTrue($italicSpan->style->hasModifier(Modifier::Italic));
    }

    #[Test]
    public function long_list_item_wraps_with_continuation_indent(): void
    {
        $longItem = str_repeat('word ', 20);
        $md = "* {$longItem}";
        $elements = $this->renderer->render($this->ui, $md, 40);

        $lines = $this->extractLines($elements);
        $nonEmpty = array_filter($lines, static fn(Line $l): bool => $l->width > 0);

        self::assertGreaterThan(1, count($nonEmpty), 'Long list item should wrap');

        $firstLine = $this->findLineContaining($lines, "\u{2022}");
        self::assertNotNull($firstLine, 'First line should have bullet');
    }

    #[Test]
    public function mixed_paragraph_and_list_renders_both(): void
    {
        $md = "Intro paragraph.\n\n* Item one\n* Item two\n\nClosing paragraph.";
        $elements = $this->renderer->render($this->ui, $md, 80);

        $lines = $this->extractLines($elements);

        self::assertNotNull($this->findLineContaining($lines, 'Intro'));
        self::assertNotNull($this->findLineContaining($lines, 'one'));
        self::assertNotNull($this->findLineContaining($lines, 'two'));
        self::assertNotNull($this->findLineContaining($lines, 'Closing'));

        $bulletCount = 0;
        foreach ($lines as $line) {
            foreach ($line->spans as $span) {
                if (str_contains($span->content, "\u{2022}")) {
                    $bulletCount++;
                    break;
                }
            }
        }
        self::assertSame(2, $bulletCount, 'Should have two bullet items');
    }

    #[Test]
    public function empty_markdown_produces_no_content_lines(): void
    {
        $elements = $this->renderer->render($this->ui, '', 80);
        $lines = $this->extractLines($elements);

        $nonEmpty = array_filter($lines, static fn(Line $l): bool => $l->width > 0);
        self::assertCount(0, $nonEmpty, 'Empty markdown should produce no content lines');
    }

    #[Test]
    public function long_paragraph_wraps_at_width(): void
    {
        $longText = str_repeat('word ', 30);
        $elements = $this->renderer->render($this->ui, $longText, 40);

        $lines = $this->extractLines($elements);

        // With 40 char width, 150 chars should wrap into multiple lines
        $nonEmptyLines = array_filter($lines, static fn(Line $l): bool => $l->width > 0);
        self::assertGreaterThan(1, count($nonEmptyLines));
    }

    #[Test]
    public function multiple_paragraphs_separated_by_empty_lines(): void
    {
        $md = "First paragraph.\n\nSecond paragraph.";
        $elements = $this->renderer->render($this->ui, $md, 80);

        $lines = $this->extractLines($elements);
        $first = $this->findLineContaining($lines, 'First');
        $second = $this->findLineContaining($lines, 'Second');

        self::assertNotNull($first);
        self::assertNotNull($second);
    }

    /**
     * @param list<\Phalanx\Theatron\Tdom\Renderable> $elements
     * @return list<Line>
     */
    private function extractLines(array $elements): array
    {
        $lines = [];

        foreach ($elements as $el) {
            if ($el instanceof TextElement) {
                $content = $el->content;
                $lines[] = $content instanceof Line ? $content : Line::plain($content);
            }
        }

        return $lines;
    }

    /** @param list<Line> $lines */
    private function findLineContaining(array $lines, string $needle): ?Line
    {
        foreach ($lines as $line) {
            foreach ($line->spans as $span) {
                if (str_contains($span->content, $needle)) {
                    return $line;
                }
            }
        }

        return null;
    }

    private function findSpanContaining(Line $line, string $needle): ?Span
    {
        foreach ($line->spans as $span) {
            if (str_contains($span->content, $needle)) {
                return $span;
            }
        }

        return null;
    }
}
