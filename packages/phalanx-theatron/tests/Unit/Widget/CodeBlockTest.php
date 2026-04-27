<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Widget;

use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Widget\CodeBlock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeBlockTest extends TestCase
{
    #[Test]
    public function renders_php_code_with_line_numbers(): void
    {
        $code = <<<'PHP'
        final class Foo
        {
            public function bar(): void {}
        }
        PHP;

        $block = new CodeBlock($code);

        $buf = Buffer::empty(50, 6);
        $block->render(Rect::sized(50, 6), $buf);

        self::assertSame('1', $buf->get(2, 0)->char);
        self::assertSame('│', $buf->get(3, 0)->char);
    }

    #[Test]
    public function highlight_line_shows_marker(): void
    {
        $code = "line1\nline2\nline3";
        $block = new CodeBlock($code, startLine: 1, highlightLine: 2);

        $buf = Buffer::empty(30, 5);
        $block->render(Rect::sized(30, 5), $buf);

        self::assertSame(' ', $buf->get(0, 0)->char);
        self::assertSame('>', $buf->get(0, 1)->char);
    }

    #[Test]
    public function custom_start_line(): void
    {
        $code = "alpha\nbeta";
        $block = new CodeBlock($code, startLine: 42);

        $buf = Buffer::empty(30, 3);
        $block->render(Rect::sized(30, 3), $buf);

        self::assertSame('4', $buf->get(1, 0)->char);
        self::assertSame('2', $buf->get(2, 0)->char);
    }
}
