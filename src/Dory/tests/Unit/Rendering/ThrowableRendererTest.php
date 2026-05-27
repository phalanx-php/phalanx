<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit\Rendering;

use Phalanx\Dory\Rendering\ThrowableRenderer;
use Phalanx\Dory\Tests\Fixtures\BufferSink;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThrowableRendererTest extends TestCase
{
    #[Test]
    public function supports_throwable(): void
    {
        $renderer = new ThrowableRenderer();

        self::assertTrue($renderer->supports(new \RuntimeException('breach')));
    }

    #[Test]
    public function supports_error(): void
    {
        $renderer = new ThrowableRenderer();

        self::assertTrue($renderer->supports(new \Error('type error')));
    }

    #[Test]
    public function does_not_support_non_throwable(): void
    {
        $renderer = new ThrowableRenderer();

        self::assertFalse($renderer->supports('not an exception'));
        self::assertFalse($renderer->supports(42));
        self::assertFalse($renderer->supports(new \stdClass()));
    }

    #[Test]
    public function renders_class_and_message(): void
    {
        $renderer = new ThrowableRenderer();
        $sink = new BufferSink();

        $exception = new \RuntimeException('the phalanx broke');

        $renderer->render($exception, $sink);

        self::assertSame('RuntimeException: the phalanx broke', $sink->lines[0]);
    }

    #[Test]
    public function renders_file_and_line(): void
    {
        $renderer = new ThrowableRenderer();
        $sink = new BufferSink();

        $exception = new \RuntimeException('shield wall');

        $renderer->render($exception, $sink);

        self::assertCount(2, $sink->lines);
        self::assertStringStartsWith('  at ', $sink->lines[1]);
        self::assertStringContainsString(__FILE__, $sink->lines[1]);
    }

    #[Test]
    public function renders_previous_exception(): void
    {
        $renderer = new ThrowableRenderer();
        $sink = new BufferSink();

        $previous = new \InvalidArgumentException('bad formation');
        $exception = new \RuntimeException('battle lost', 0, $previous);

        $renderer->render($exception, $sink);

        self::assertCount(3, $sink->lines);
        self::assertSame('  caused by: InvalidArgumentException: bad formation', $sink->lines[2]);
    }

    #[Test]
    public function renders_only_immediate_previous(): void
    {
        $renderer = new ThrowableRenderer();
        $sink = new BufferSink();

        $root = new \LogicException('supply line cut');
        $middle = new \RuntimeException('formation broke', 0, $root);
        $outer = new \RuntimeException('battle lost', 0, $middle);

        $renderer->render($outer, $sink);

        self::assertCount(3, $sink->lines);
        self::assertSame('RuntimeException: battle lost', $sink->lines[0]);
        self::assertStringStartsWith('  at ', $sink->lines[1]);
        self::assertSame('  caused by: RuntimeException: formation broke', $sink->lines[2]);
    }

    #[Test]
    public function omits_caused_by_when_no_previous(): void
    {
        $renderer = new ThrowableRenderer();
        $sink = new BufferSink();

        $exception = new \LogicException('no cause');

        $renderer->render($exception, $sink);

        self::assertCount(2, $sink->lines);
    }
}
