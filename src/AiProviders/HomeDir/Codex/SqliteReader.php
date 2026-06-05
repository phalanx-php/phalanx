<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\HomeDir\Codex;

/**
 * Lazy streaming reader for Codex's SQLite conversation database
 * (`~/.codex/logs_2.sqlite`).
 *
 * Assumed schema (documented assumption — Codex does not publish a formal
 * schema; this reader targets the flat `events` table shape observed in
 * Codex 0.7+ installations):
 *
 * ```sql
 * CREATE TABLE events (
 *     id       INTEGER PRIMARY KEY AUTOINCREMENT,
 *     ts       INTEGER NOT NULL,   -- Unix timestamp (seconds)
 *     type     TEXT    NOT NULL,   -- e.g. "message", "tool_call", "tool_result"
 *     role     TEXT,               -- "user", "assistant", "system"
 *     content  TEXT,               -- JSON-encoded or plain-text content
 *     raw_hash TEXT                -- SHA-256 of canonical content, for dedup
 * );
 * ```
 *
 * If the actual schema differs the query will fail at runtime with a
 * SQLite3Exception, which the caller ({@see Source\All}) treats as a
 * non-fatal source absence.
 *
 * Requires `ext-sqlite3`. When absent, {@see self::read()} throws
 * {@see SqliteUnavailable} before opening any file. Callers should
 * catch that type for graceful degradation.
 *
 * Final — reader implementations are sealed.
 */
final class SqliteReader
{
    private function __construct()
    {
    }

    /**
     * Stream rows from the Codex events table ordered by timestamp (ascending).
     * Each yielded value is an associative array matching the events schema.
     *
     * @throws SqliteUnavailable when the sqlite3 PHP extension is not loaded
     * @return \Generator<array<string, mixed>>
     */
    public static function read(string $path): \Generator
    {
        if (!extension_loaded('sqlite3')) {
            throw SqliteUnavailable::extensionMissing();
        }

        if (!is_file($path)) {
            return;
        }

        $db = new \SQLite3($path, \SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);

        try {
            $result = $db->query('SELECT id, ts, type, role, content, raw_hash FROM events ORDER BY ts ASC, id ASC');

            if ($result === false) {
                return;
            }

            while (true) {
                $row = $result->fetchArray(\SQLITE3_ASSOC);

                if ($row === false) {
                    break;
                }

                /** @var array<string, mixed> $row */
                yield $row;
            }

            $result->finalize();
        } finally {
            $db->close();
        }
    }
}
