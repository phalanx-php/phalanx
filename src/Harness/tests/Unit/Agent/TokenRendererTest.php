<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Agent;

use Phalanx\Harness\Agent\TokenRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TokenRendererTest extends TestCase
{
    #[Test]
    public function appendReturnsCompleteLines(): void
    {
        $renderer = new TokenRenderer();

        $result = $renderer->append("Hello\nWorld");

        self::assertSame("Hello\n", $result);
    }

    #[Test]
    public function appendBuffersPartialLine(): void
    {
        $renderer = new TokenRenderer();

        $result = $renderer->append('Hello');

        self::assertSame('', $result);
        self::assertSame('Hello', $renderer->flush());
    }

    #[Test]
    public function multipleAppendsAccumulate(): void
    {
        $renderer = new TokenRenderer();

        $first = $renderer->append('Hel');
        $second = $renderer->append("lo\n");

        self::assertSame('', $first);
        self::assertSame("Hello\n", $second);
    }

    #[Test]
    public function flushReturnsRemaining(): void
    {
        $renderer = new TokenRenderer();
        $renderer->append('partial');

        self::assertSame('partial', $renderer->flush());
    }

    #[Test]
    public function flushClearsBuffer(): void
    {
        $renderer = new TokenRenderer();
        $renderer->append('partial');
        $renderer->flush();

        self::assertSame('', $renderer->flush());
    }

    #[Test]
    public function channelSwitchFlushesBuffer(): void
    {
        $renderer = new TokenRenderer();

        $renderer->append('pondering...', 'thinking');
        $result = $renderer->append("The hoplites advance.\n", 'message');

        self::assertStringContainsString('pondering...', $result);
        self::assertStringContainsString("The hoplites advance.\n", $result);
    }

    #[Test]
    public function channelReturnsCurrentChannel(): void
    {
        $renderer = new TokenRenderer();

        self::assertSame('message', $renderer->channel());

        $renderer->append('Leonidas commands.', 'thinking');

        self::assertSame('thinking', $renderer->channel());
    }

    #[Test]
    public function emptyTextReturnsEmpty(): void
    {
        $renderer = new TokenRenderer();

        self::assertSame('', $renderer->append(''));
    }

    #[Test]
    public function multipleNewlinesAllEmitted(): void
    {
        $renderer = new TokenRenderer();

        $result = $renderer->append("a\nb\nc\n");

        self::assertSame("a\nb\nc\n", $result);
        self::assertSame('', $renderer->flush());
    }

    #[Test]
    public function noNewlineBuffersEverything(): void
    {
        $renderer = new TokenRenderer();

        $result = $renderer->append('abc');

        self::assertSame('', $result);
        self::assertSame('abc', $renderer->flush());
    }
}
