<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Support;

/**
 * Builds a temporary SQLite database for Codex SqliteReader tests.
 * Creates the `events` table matching the schema documented in
 * {@see \Phalanx\Panoply\HomeDir\Codex\SqliteReader} and populates it
 * with a small set of test rows.
 *
 * Usage:
 * ```php
 * $path = SqliteFixtureBuilder::build();
 * // ... run assertions against the path ...
 * SqliteFixtureBuilder::cleanup($path);
 * ```
 *
 * Final — support class; no extension needed.
 */
final class SqliteFixtureBuilder
{
    private function __construct()
    {
    }

    /**
     * Build a temporary SQLite file with the Codex events schema and seed rows.
     * Returns the absolute path to the created file.
     */
    public static function build(): string
    {
        $path = sys_get_temp_dir() . '/panoply_codex_test_' . bin2hex(random_bytes(6)) . '.sqlite';

        $db = new \SQLite3($path);
        $db->enableExceptions(true);

        $db->exec('
            CREATE TABLE events (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                ts       INTEGER NOT NULL,
                type     TEXT    NOT NULL,
                role     TEXT,
                content  TEXT,
                raw_hash TEXT
            )
        ');

        $stmt = $db->prepare('
            INSERT INTO events (ts, type, role, content, raw_hash)
            VALUES (:ts, :type, :role, :content, :raw_hash)
        ') ?: throw new \RuntimeException('Failed to prepare INSERT statement');

        $rows = [
            [
                'ts' => 1747468800,
                'type' => 'message',
                'role' => 'system',
                'content' => 'You are a Spartan hoplite advising on phalanx formations.',
                'raw_hash' => 'sqlite_hash_system_01',
            ],
            [
                'ts' => 1747468810,
                'type' => 'message',
                'role' => 'user',
                'content' => 'How wide should the sarissa phalanx front be at Olympus?',
                'raw_hash' => 'sqlite_hash_user_01',
            ],
            [
                'ts' => 1747468820,
                'type' => 'tool_call',
                'role' => 'assistant',
                'content' => '{"width":"sixteen_ranks","terrain":"mountain_pass"}',
                'raw_hash' => 'sqlite_hash_tool_01',
            ],
            [
                'ts' => 1747468830,
                'type' => 'tool_result',
                'role' => 'user',
                'content' => 'Formation optimal at sixteen ranks on uphill terrain.',
                'raw_hash' => 'sqlite_hash_result_01',
            ],
            [
                'ts' => 1747468840,
                'type' => 'message',
                'role' => 'assistant',
                'content' => 'The sarissa phalanx holds the high ground. Victory to Macedon.',
                'raw_hash' => 'sqlite_hash_asst_01',
            ],
        ];

        foreach ($rows as $row) {
            $stmt->bindValue(':ts', $row['ts'], \SQLITE3_INTEGER);
            $stmt->bindValue(':type', $row['type'], \SQLITE3_TEXT);
            $stmt->bindValue(':role', $row['role'], \SQLITE3_TEXT);
            $stmt->bindValue(':content', $row['content'], \SQLITE3_TEXT);
            $stmt->bindValue(':raw_hash', $row['raw_hash'], \SQLITE3_TEXT);
            $stmt->execute();
            $stmt->reset();
        }

        $stmt->close();
        $db->close();

        return $path;
    }

    /**
     * Delete the temporary database file created by {@see self::build()}.
     */
    public static function cleanup(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
