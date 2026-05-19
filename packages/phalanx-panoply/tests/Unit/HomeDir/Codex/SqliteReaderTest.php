<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\HomeDir\Codex;

use Phalanx\Panoply\HomeDir\Codex\SqliteReader;
use Phalanx\Panoply\HomeDir\Codex\SqliteUnavailable;
use Phalanx\Panoply\Tests\Support\SqliteFixtureBuilder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Codex SQLite reader. All tests that open a real database
 * require the `sqlite3` extension and are decorated with
 * {@see RequiresPhpExtension} so they are skipped gracefully when absent.
 */
final class SqliteReaderTest extends TestCase
{
    private ?string $dbPath = null;

    #[Test]
    #[RequiresPhpExtension('sqlite3')]
    public function readsAllRowsFromDatabase(): void
    {
        $rows = iterator_to_array(SqliteReader::read($this->path()));

        self::assertCount(5, $rows);
    }

    #[Test]
    #[RequiresPhpExtension('sqlite3')]
    public function rowsAreOrderedByTimestamp(): void
    {
        $rows = iterator_to_array(SqliteReader::read($this->path()));

        $timestamps = array_column($rows, 'ts');
        $sorted = $timestamps;
        sort($sorted);

        self::assertSame($sorted, $timestamps);
    }

    #[Test]
    #[RequiresPhpExtension('sqlite3')]
    public function rowsContainExpectedColumns(): void
    {
        $rows = iterator_to_array(SqliteReader::read($this->path()));

        self::assertArrayHasKey('id', $rows[0]);
        self::assertArrayHasKey('ts', $rows[0]);
        self::assertArrayHasKey('type', $rows[0]);
        self::assertArrayHasKey('role', $rows[0]);
        self::assertArrayHasKey('content', $rows[0]);
        self::assertArrayHasKey('raw_hash', $rows[0]);
    }

    #[Test]
    #[RequiresPhpExtension('sqlite3')]
    public function firstRowIsSystemMessage(): void
    {
        $rows = iterator_to_array(SqliteReader::read($this->path()));

        self::assertSame('message', $rows[0]['type']);
        self::assertSame('system', $rows[0]['role']);
    }

    #[Test]
    #[RequiresPhpExtension('sqlite3')]
    public function nonExistentFileProducesEmptyGenerator(): void
    {
        $rows = iterator_to_array(SqliteReader::read('/does/not/exist.sqlite'));

        self::assertCount(0, $rows);
    }

    #[Test]
    #[RequiresPhpExtension('sqlite3')]
    public function rawHashColumnIsPopulated(): void
    {
        $rows = iterator_to_array(SqliteReader::read($this->path()));

        foreach ($rows as $row) {
            self::assertNotEmpty($row['raw_hash']);
        }
    }

    #[Test]
    public function sqliteUnavailableHasHelpfulMessage(): void
    {
        $exception = SqliteUnavailable::extensionMissing();

        self::assertStringContainsString('sqlite3', $exception->getMessage());
        self::assertStringContainsString('ext-sqlite3', $exception->getMessage());
    }

    #[RequiresPhpExtension('sqlite3')]
    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = SqliteFixtureBuilder::build();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->dbPath !== null) {
            SqliteFixtureBuilder::cleanup($this->dbPath);
            $this->dbPath = null;
        }
    }

    /** Returns the db path, asserting setUp() has run and it is non-null. */
    private function path(): string
    {
        self::assertNotNull($this->dbPath, 'dbPath must be set by setUp()');

        return $this->dbPath;
    }
}
