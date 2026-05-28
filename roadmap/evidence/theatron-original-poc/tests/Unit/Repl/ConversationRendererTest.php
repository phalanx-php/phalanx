<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Render\MarkdownRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationRendererTest extends TestCase
{
    #[Test]
    public function strips_h1_heading(): void
    {
        self::assertSame('Title', MarkdownRenderer::stripBlockSyntax('# Title'));
    }

    #[Test]
    public function strips_h2_through_h6_headings(): void
    {
        self::assertSame('Two', MarkdownRenderer::stripBlockSyntax('## Two'));
        self::assertSame('Three', MarkdownRenderer::stripBlockSyntax('### Three'));
        self::assertSame('Four', MarkdownRenderer::stripBlockSyntax('#### Four'));
        self::assertSame('Five', MarkdownRenderer::stripBlockSyntax('##### Five'));
        self::assertSame('Six', MarkdownRenderer::stripBlockSyntax('###### Six'));
    }

    #[Test]
    public function preserves_inline_hash_not_at_line_start(): void
    {
        self::assertSame('Use a # comment', MarkdownRenderer::stripBlockSyntax('Use a # comment'));
    }

    #[Test]
    public function strips_fenced_code_block_markers(): void
    {
        $input = "```php\necho 'hello';\n```";
        $expected = "\necho 'hello';\n";

        self::assertSame($expected, MarkdownRenderer::stripBlockSyntax($input));
    }

    #[Test]
    public function strips_fenced_code_block_without_language(): void
    {
        $input = "```\ncode here\n```";
        $expected = "\ncode here\n";

        self::assertSame($expected, MarkdownRenderer::stripBlockSyntax($input));
    }

    #[Test]
    public function preserves_inline_backticks(): void
    {
        self::assertSame('Use `$scope->call()` here', MarkdownRenderer::stripBlockSyntax('Use `$scope->call()` here'));
    }

    #[Test]
    public function strips_dash_bullet_list(): void
    {
        $input = "- First item\n- Second item";
        $expected = "First item\nSecond item";

        self::assertSame($expected, MarkdownRenderer::stripBlockSyntax($input));
    }

    #[Test]
    public function strips_asterisk_bullet_list(): void
    {
        $input = "* Alpha\n* Beta";
        $expected = "Alpha\nBeta";

        self::assertSame($expected, MarkdownRenderer::stripBlockSyntax($input));
    }

    #[Test]
    public function preserves_mid_line_asterisk(): void
    {
        self::assertSame('This is *bold* text', MarkdownRenderer::stripBlockSyntax('This is *bold* text'));
    }

    #[Test]
    public function preserves_mid_line_dash(): void
    {
        self::assertSame('A - B - C', MarkdownRenderer::stripBlockSyntax('A - B - C'));
    }

    #[Test]
    public function strips_ordered_list_markers(): void
    {
        $input = "1. First\n2. Second\n3. Third";
        $expected = "First\nSecond\nThird";

        self::assertSame($expected, MarkdownRenderer::stripBlockSyntax($input));
    }

    #[Test]
    public function preserves_numbers_not_at_line_start(): void
    {
        self::assertSame('Step 1. done', MarkdownRenderer::stripBlockSyntax('Step 1. done'));
    }

    #[Test]
    public function handles_mixed_block_syntax(): void
    {
        $input = "# Summary\n\n- Point one\n- Point two\n\n1. Step one\n2. Step two";
        $expected = "Summary\n\nPoint one\nPoint two\n\nStep one\nStep two";

        self::assertSame($expected, MarkdownRenderer::stripBlockSyntax($input));
    }

    #[Test]
    public function returns_empty_string_for_empty_input(): void
    {
        self::assertSame('', MarkdownRenderer::stripBlockSyntax(''));
    }

    #[Test]
    public function plain_text_passes_through_unchanged(): void
    {
        $text = 'The phalanx holds the line at Thermopylae.';

        self::assertSame($text, MarkdownRenderer::stripBlockSyntax($text));
    }

    #[Test]
    public function multiline_plain_text_passes_through(): void
    {
        $text = "First paragraph.\n\nSecond paragraph.";

        self::assertSame($text, MarkdownRenderer::stripBlockSyntax($text));
    }

    #[Test]
    public function heading_with_inline_formatting_preserved(): void
    {
        self::assertSame('**Bold** title', MarkdownRenderer::stripBlockSyntax('## **Bold** title'));
    }

    #[Test]
    public function strips_heading_on_each_line_independently(): void
    {
        $input = "# First\nBody text\n## Second";
        $expected = "First\nBody text\nSecond";

        self::assertSame($expected, MarkdownRenderer::stripBlockSyntax($input));
    }

    #[Test]
    public function does_not_strip_heading_without_space(): void
    {
        self::assertSame('#NoSpace', MarkdownRenderer::stripBlockSyntax('#NoSpace'));
    }

    #[Test]
    public function double_digit_ordered_list(): void
    {
        $input = "10. Tenth item\n99. Ninety-ninth";
        $expected = "Tenth item\nNinety-ninth";

        self::assertSame($expected, MarkdownRenderer::stripBlockSyntax($input));
    }

    #[Test]
    public function strips_indented_code_block_prefix(): void
    {
        $input = "    \$x = 1;\n    echo \$x;";
        $expected = "\$x = 1;\necho \$x;";

        self::assertSame($expected, MarkdownRenderer::stripBlockSyntax($input));
    }

    #[Test]
    public function preserves_thematic_break(): void
    {
        self::assertSame('***', MarkdownRenderer::stripBlockSyntax('***'));
        self::assertSame('---', MarkdownRenderer::stripBlockSyntax('---'));
    }

    #[Test]
    public function strips_bold_markers(): void
    {
        self::assertSame('bold text', MarkdownRenderer::stripInlineSyntax('**bold text**'));
    }

    #[Test]
    public function strips_italic_markers(): void
    {
        self::assertSame('italic text', MarkdownRenderer::stripInlineSyntax('*italic text*'));
    }

    #[Test]
    public function strips_inline_code(): void
    {
        self::assertSame('code here', MarkdownRenderer::stripInlineSyntax('`code here`'));
    }

    #[Test]
    public function strips_link_syntax(): void
    {
        self::assertSame('click here', MarkdownRenderer::stripInlineSyntax('[click here](https://example.com)'));
    }

    #[Test]
    public function strips_mixed_inline_syntax(): void
    {
        $input = 'Use **bold** and *italic* with `code` and [links](http://example.com).';
        $expected = 'Use bold and italic with code and links.';

        self::assertSame($expected, MarkdownRenderer::stripInlineSyntax($input));
    }

    #[Test]
    public function inline_strip_preserves_plain_text(): void
    {
        $text = 'No markdown here at all.';

        self::assertSame($text, MarkdownRenderer::stripInlineSyntax($text));
    }

    #[Test]
    public function strips_bold_within_sentence(): void
    {
        self::assertSame('The Dispersed Forces: divide troops', MarkdownRenderer::stripInlineSyntax('The **Dispersed Forces**: divide troops'));
    }
}
