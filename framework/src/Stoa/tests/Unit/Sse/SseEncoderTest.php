<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Sse;

use Phalanx\Stoa\Sse\SseEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SseEncoderTest extends TestCase
{
    #[Test]
    public function singleLineDataYieldsSingleDataField(): void
    {
        self::assertSame("data: hello\n\n", SseEncoder::event('hello'));
    }

    #[Test]
    public function multiLineDataSplitsAcrossDataFields(): void
    {
        $encoded = SseEncoder::event("line one\nline two\nline three");

        self::assertSame("data: line one\ndata: line two\ndata: line three\n\n", $encoded);
    }

    #[Test]
    public function eventNameIdAndRetryAreIncludedInOrder(): void
    {
        $encoded = SseEncoder::event(
            data: 'payload',
            event: 'tick',
            id: '42',
            retryMs: 5000,
        );

        self::assertSame(
            "id: 42\nevent: tick\nretry: 5000\ndata: payload\n\n",
            $encoded,
        );
    }

    #[Test]
    public function emptyEventAndIdAreOmitted(): void
    {
        $encoded = SseEncoder::event(data: 'x', event: '', id: '');

        self::assertSame("data: x\n\n", $encoded);
    }

    #[Test]
    public function newlinesInEventNameAreStripped(): void
    {
        $encoded = SseEncoder::event(data: 'x', event: "ti\nck");

        self::assertStringContainsString('event: tick', $encoded);
    }

    #[Test]
    public function mixedNewlineSplitterHandlesCrlfAndCr(): void
    {
        $encoded = SseEncoder::event("a\r\nb\rc\nd");

        self::assertSame("data: a\ndata: b\ndata: c\ndata: d\n\n", $encoded);
    }

    #[Test]
    public function commentEmitsColonPrefixedLines(): void
    {
        self::assertSame(": ping\n\n", SseEncoder::comment('ping'));
        self::assertSame(": a\n: b\n\n", SseEncoder::comment("a\nb"));
    }

    #[Test]
    public function negativeOrZeroRetryIsOmitted(): void
    {
        self::assertStringNotContainsString('retry:', SseEncoder::event('x', retryMs: 0));
        self::assertStringNotContainsString('retry:', SseEncoder::event('x', retryMs: -1));
    }
}
