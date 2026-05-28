<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Kit;

use Phalanx\Theatron\Kit\FrameLoop;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameLoopTest extends TestCase
{
    #[Test]
    public function starts_with_zero_frames_and_needs_draw(): void
    {
        $loop = new FrameLoop();

        self::assertSame(0, $loop->frames);
        self::assertTrue($loop->needsDraw);
    }

    #[Test]
    public function tick_increments_frame_count(): void
    {
        $loop = new FrameLoop();
        $loop->tick();
        $loop->tick();
        $loop->tick();

        self::assertSame(3, $loop->frames);
    }

    #[Test]
    public function consume_returns_true_and_clears_needs_draw(): void
    {
        $loop = new FrameLoop();

        self::assertTrue($loop->consume());
        self::assertFalse($loop->needsDraw);
    }

    #[Test]
    public function consume_returns_false_when_not_dirty(): void
    {
        $loop = new FrameLoop();
        $loop->consume();

        self::assertFalse($loop->consume());
    }

    #[Test]
    public function invalidate_marks_needs_draw(): void
    {
        $loop = new FrameLoop();
        $loop->consume();

        self::assertFalse($loop->needsDraw);

        $loop->invalidate();

        self::assertTrue($loop->needsDraw);
        self::assertTrue($loop->consume());
    }

    #[Test]
    public function should_draw_returns_true_when_needs_draw(): void
    {
        $loop = new FrameLoop();

        self::assertTrue($loop->shouldDraw(false));
        self::assertFalse($loop->needsDraw);
    }

    #[Test]
    public function should_draw_returns_true_when_component_dirty(): void
    {
        $loop = new FrameLoop();
        $loop->consume();

        self::assertTrue($loop->shouldDraw(true));
    }

    #[Test]
    public function should_draw_returns_false_when_clean(): void
    {
        $loop = new FrameLoop();
        $loop->consume();

        self::assertFalse($loop->shouldDraw(false));
    }

    #[Test]
    public function elapsed_seconds_is_positive(): void
    {
        $loop = new FrameLoop();

        self::assertGreaterThanOrEqual(0.0, $loop->elapsedSeconds());
    }

    #[Test]
    public function fps_returns_zero_initially(): void
    {
        $loop = new FrameLoop();

        self::assertSame(0.0, $loop->fps());
    }
}
