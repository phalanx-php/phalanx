<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Ndjson;

use Phalanx\Panoply\Ndjson\Reader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase
{
    #[Test]
    public function singleCompleteLineYieldsOneParsedObject(): void
    {
        $reader = new Reader();
        $lines  = iterator_to_array($reader->feed("{\"model\":\"llama3.1\",\"done\":false}\n"), preserve_keys: false);

        self::assertCount(1, $lines);
        self::assertSame('llama3.1', $lines[0]['model']);
        self::assertFalse($lines[0]['done']);
    }

    #[Test]
    public function twoLinesInOneChunkYieldsTwoObjects(): void
    {
        $reader = new Reader();
        $lines  = iterator_to_array(
            $reader->feed("{\"a\":1}\n{\"b\":2}\n"),
            preserve_keys: false,
        );

        self::assertCount(2, $lines);
        self::assertSame(1, $lines[0]['a']);
        self::assertSame(2, $lines[1]['b']);
    }

    #[Test]
    public function splitLineAcrossTwoChunksYieldsOneObject(): void
    {
        $reader = new Reader();

        $first  = iterator_to_array($reader->feed("{\"model\":\"llama"), preserve_keys: false);
        $second = iterator_to_array($reader->feed("3.1\"}\n"), preserve_keys: false);

        self::assertCount(0, $first);
        self::assertCount(1, $second);
        self::assertSame('llama3.1', $second[0]['model']);
    }

    #[Test]
    public function flushYieldsTrailingLineWithoutNewline(): void
    {
        $reader = new Reader();

        iterator_to_array($reader->feed("{\"partial\":true}"), preserve_keys: false);
        $flushed = iterator_to_array($reader->flush(), preserve_keys: false);

        self::assertCount(1, $flushed);
        self::assertTrue($flushed[0]['partial']);
    }

    #[Test]
    public function malformedJsonLineIsSilentlyDropped(): void
    {
        $reader = new Reader();

        $lines = iterator_to_array(
            $reader->feed("not-valid-json\n{\"ok\":true}\n"),
            preserve_keys: false,
        );

        self::assertCount(1, $lines);
        self::assertTrue($lines[0]['ok']);
    }

    #[Test]
    public function emptyChunkYieldsNothing(): void
    {
        $reader = new Reader();

        $lines = iterator_to_array($reader->feed(''), preserve_keys: false);

        self::assertCount(0, $lines);
    }

    #[Test]
    public function crlfLineEndingNormalizedToLf(): void
    {
        $reader = new Reader();

        $lines = iterator_to_array($reader->feed("{\"crlf\":true}\r\n"), preserve_keys: false);

        self::assertCount(1, $lines);
        self::assertTrue($lines[0]['crlf']);
    }

    #[Test]
    public function bareCrNormalizedToLf(): void
    {
        $reader = new Reader();

        // Bare CR is normalized to LF during feed(); the line is immediately
        // complete and yielded — no separate flush needed.
        $lines = iterator_to_array($reader->feed("{\"cr\":true}\r"), preserve_keys: false);

        self::assertCount(1, $lines);
        self::assertTrue($lines[0]['cr']);
    }

    #[Test]
    public function emptyFlushYieldsNothing(): void
    {
        $reader  = new Reader();
        $flushed = iterator_to_array($reader->flush(), preserve_keys: false);

        self::assertCount(0, $flushed);
    }
}
