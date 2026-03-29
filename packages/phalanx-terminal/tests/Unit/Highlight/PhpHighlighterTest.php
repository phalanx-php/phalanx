<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Tests\Unit\Highlight;

use Phalanx\Terminal\Highlight\PhpHighlighter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpHighlighterTest extends TestCase
{
    private PhpHighlighter $highlighter;

    protected function setUp(): void
    {
        $this->highlighter = new PhpHighlighter();
    }

    #[Test]
    public function highlights_simple_code(): void
    {
        $lines = $this->highlighter->highlight('$x = 42;');

        self::assertNotEmpty($lines);
        self::assertGreaterThan(0, $lines[0]->width);
    }

    #[Test]
    public function preserves_line_breaks(): void
    {
        $lines = $this->highlighter->highlight("line1;\nline2;\nline3;");

        self::assertCount(3, $lines);
    }

    #[Test]
    public function handles_full_php_file(): void
    {
        $code = <<<'PHP'
        <?php

        declare(strict_types=1);

        final class Foo
        {
            public function bar(): string
            {
                return 'hello';
            }
        }
        PHP;

        $lines = $this->highlighter->highlight($code);

        self::assertGreaterThan(5, count($lines));
    }

    #[Test]
    public function handles_empty_code(): void
    {
        $lines = $this->highlighter->highlight('');

        self::assertNotEmpty($lines);
    }

    #[Test]
    public function handles_code_without_php_tag(): void
    {
        $lines = $this->highlighter->highlight('echo "hello";');

        self::assertNotEmpty($lines);

        $fullText = '';
        foreach ($lines[0]->spans as $span) {
            $fullText .= $span->content;
        }

        self::assertStringContainsString('echo', $fullText);
    }

    #[Test]
    public function handles_multiline_comment(): void
    {
        $code = "/* this\nis\na comment */\n\$x = 1;";
        $lines = $this->highlighter->highlight($code);

        self::assertGreaterThanOrEqual(4, count($lines));
    }
}
