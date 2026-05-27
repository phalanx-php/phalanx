<?php

declare(strict_types=1);

namespace Phalanx\Dory\Tests\Unit\Rendering;

use Phalanx\Dory\Rendering\ScalarRenderer;
use Phalanx\Dory\Tests\Fixtures\BufferSink;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScalarRendererTest extends TestCase
{
    #[Test]
    public function supports_string(): void
    {
        $renderer = new ScalarRenderer();

        self::assertTrue($renderer->supports('sparta'));
    }

    #[Test]
    public function supports_integer(): void
    {
        $renderer = new ScalarRenderer();

        self::assertTrue($renderer->supports(42));
    }

    #[Test]
    public function supports_float(): void
    {
        $renderer = new ScalarRenderer();

        self::assertTrue($renderer->supports(3.14));
    }

    #[Test]
    public function supports_bool(): void
    {
        $renderer = new ScalarRenderer();

        self::assertTrue($renderer->supports(true));
        self::assertTrue($renderer->supports(false));
    }

    #[Test]
    public function supports_null(): void
    {
        $renderer = new ScalarRenderer();

        self::assertTrue($renderer->supports(null));
    }

    #[Test]
    public function does_not_support_array(): void
    {
        $renderer = new ScalarRenderer();

        self::assertFalse($renderer->supports(['hoplite']));
    }

    #[Test]
    public function does_not_support_object(): void
    {
        $renderer = new ScalarRenderer();

        self::assertFalse($renderer->supports(new \stdClass()));
    }

    #[Test]
    public function renders_string_as_passthrough(): void
    {
        $renderer = new ScalarRenderer();
        $sink = new BufferSink();

        $renderer->render('the gates of thermopylae', $sink);

        self::assertSame(['the gates of thermopylae'], $sink->lines);
    }

    #[Test]
    public function renders_integer_as_string(): void
    {
        $renderer = new ScalarRenderer();
        $sink = new BufferSink();

        $renderer->render(300, $sink);

        self::assertSame(['300'], $sink->lines);
    }

    #[Test]
    public function renders_float_as_string(): void
    {
        $renderer = new ScalarRenderer();
        $sink = new BufferSink();

        $renderer->render(2.718, $sink);

        self::assertSame(['2.718'], $sink->lines);
    }

    #[Test]
    public function renders_true_as_word(): void
    {
        $renderer = new ScalarRenderer();
        $sink = new BufferSink();

        $renderer->render(true, $sink);

        self::assertSame(['true'], $sink->lines);
    }

    #[Test]
    public function renders_false_as_word(): void
    {
        $renderer = new ScalarRenderer();
        $sink = new BufferSink();

        $renderer->render(false, $sink);

        self::assertSame(['false'], $sink->lines);
    }

    #[Test]
    public function renders_null_as_word(): void
    {
        $renderer = new ScalarRenderer();
        $sink = new BufferSink();

        $renderer->render(null, $sink);

        self::assertSame(['null'], $sink->lines);
    }
}
