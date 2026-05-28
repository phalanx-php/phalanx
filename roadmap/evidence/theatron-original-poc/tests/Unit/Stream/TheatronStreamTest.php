<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Stream;

use Phalanx\Theatron\Stream\StreamEvent;
use Phalanx\Theatron\Stream\TheatronStream;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('openswoole')]
final class TheatronStreamTest extends TestCase
{
    #[Test]
    public function subscribe_rejects_non_static_closure(): void
    {
        $stream = new TheatronStream();
        $captured = $this;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('static closures');

        $stream->subscribe(TestStreamEvent::class, function (TestStreamEvent $e) use ($captured): void {
            $captured->assertTrue(true);
        });
    }

    #[Test]
    public function subscribe_accepts_static_closure(): void
    {
        $stream = new TheatronStream();

        $sub = $stream->subscribe(TestStreamEvent::class, static function (TestStreamEvent $e): void {
        });

        self::assertNotNull($sub);
    }

    #[Test]
    public function subscription_dispose_is_idempotent(): void
    {
        $stream = new TheatronStream();

        $sub = $stream->subscribe(TestStreamEvent::class, static function (TestStreamEvent $e): void {
        });

        $sub->dispose();
        $sub->dispose();

        self::assertTrue(true);
    }

    #[Test]
    public function emit_without_start_does_not_throw(): void
    {
        $stream = new TheatronStream();

        $stream->emit(new TestStreamEvent('hello'));

        self::assertTrue(true);
    }
}

final class TestStreamEvent implements StreamEvent
{
    public function __construct(
        private(set) string $data,
    ) {
    }
}
