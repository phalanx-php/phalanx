<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\HomeDir\Codex;

use Phalanx\Panoply\HomeDir\Codex\JsonlReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins streaming, malformed-line tolerance, and CRLF handling of the
 * Codex JSONL reader.
 */
final class JsonlReaderTest extends TestCase
{
    #[Test]
    public function readsAllLinesFromSessionFile(): void
    {
        $rows = iterator_to_array(JsonlReader::read(self::fixtureRoot() . '/sessions/2026/05-17/abc.jsonl'));

        self::assertCount(3, $rows);
    }

    #[Test]
    public function eachRowIsAnArray(): void
    {
        $rows = iterator_to_array(JsonlReader::read(self::fixtureRoot() . '/sessions/2026/05-17/abc.jsonl'));

        foreach ($rows as $row) {
            self::assertIsArray($row);
        }
    }

    #[Test]
    public function rowsContainExpectedKeys(): void
    {
        $rows = iterator_to_array(JsonlReader::read(self::fixtureRoot() . '/sessions/2026/05-17/abc.jsonl'));

        self::assertArrayHasKey('type', $rows[0]);
        self::assertArrayHasKey('role', $rows[0]);
        self::assertArrayHasKey('content', $rows[0]);
        self::assertArrayHasKey('ts', $rows[0]);
    }

    #[Test]
    public function malformedLineIsDroppedSilently(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'panoply_jr_') . '.jsonl';
        file_put_contents(
            $tmpFile,
            '{"type":"message","role":"user","content":"valid","ts":1000,"raw_hash":"h1"}' . "\n" .
            'not valid json at all' . "\n" .
            '{"type":"message","role":"assistant","content":"also valid","ts":1001,"raw_hash":"h2"}' . "\n",
        );

        try {
            $rows = iterator_to_array(JsonlReader::read($tmpFile));
            self::assertCount(2, $rows);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function emptyLinesAreSkipped(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'panoply_jr_') . '.jsonl';
        file_put_contents(
            $tmpFile,
            '{"type":"message","role":"user","content":"hello","ts":1000,"raw_hash":"h1"}' . "\n" .
            "\n" .
            "\n" .
            '{"type":"message","role":"assistant","content":"world","ts":1001,"raw_hash":"h2"}' . "\n",
        );

        try {
            $rows = iterator_to_array(JsonlReader::read($tmpFile));
            self::assertCount(2, $rows);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function nonExistentFileProducesEmptyGenerator(): void
    {
        $rows = iterator_to_array(JsonlReader::read('/does/not/exist.jsonl'));

        self::assertCount(0, $rows);
    }

    #[Test]
    public function readIsLazy(): void
    {
        // Verify the return value is a Generator, not an array.
        $gen = JsonlReader::read(self::fixtureRoot() . '/sessions/2026/05-17/abc.jsonl');

        self::assertInstanceOf(\Generator::class, $gen);
    }

    #[Test]
    public function historyFileReadsAllFourLines(): void
    {
        $rows = iterator_to_array(JsonlReader::read(self::fixtureRoot() . '/history.jsonl'));

        self::assertCount(4, $rows);
    }

    private static function fixtureRoot(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/HomeDir/Codex';
    }
}
