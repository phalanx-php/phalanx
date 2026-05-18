<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\Codex;

use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Conversation\Options;
use Phalanx\Panoply\Conversation\Parser as ParserInterface;
use Phalanx\Panoply\Conversation\Record\Message;
use Phalanx\Panoply\Conversation\Record\ToolCall;
use Phalanx\Panoply\Conversation\Record\ToolResult;
use Phalanx\Panoply\Conversation\Record\Unknown;
use Phalanx\Panoply\Conversation\Source as ConversationSource;
use Phalanx\Panoply\Conversation\StrictMode;
use Phalanx\Panoply\HomeDir\Codex\Source\All;
use Phalanx\Panoply\HomeDir\Codex\Source\History;
use Phalanx\Panoply\HomeDir\Codex\Source\Sessions;
use Phalanx\Panoply\HomeDir\Codex\Source\Sqlite;
use Phalanx\Panoply\Id;

/**
 * Parses Codex conversation data into a normalized {@see Log}. Dispatches
 * on the concrete {@see ConversationSource} subtype:
 *
 * - {@see Sessions} — reads all `*.jsonl` files under the sessions day-tree.
 * - {@see History} — reads a single `history.jsonl` file.
 * - {@see Sqlite} — streams rows from the `events` SQLite table.
 * - {@see All} — merges all three sources chronologically with `raw_hash`
 *   deduplication via {@see \Phalanx\Panoply\Series::interleaveByDedup()}.
 *
 * Codex JSONL shape:
 * - Each line is a JSON object. The `type` field carries the record kind
 *   (`"message"`, `"tool_call"`, `"tool_result"`). The `role` field carries
 *   `"user"`, `"assistant"`, or `"system"`. The `content` field carries the
 *   text payload or a JSON-encoded structured argument map. The `ts` field
 *   carries a Unix timestamp (integer seconds). The `raw_hash` field carries
 *   a content hash used for cross-source deduplication.
 *
 * SQLite row shape mirrors the JSONL shape (same column names).
 *
 * Final — Parser implementations are sealed per vendor.
 */
final class Parser implements ParserInterface
{
    public function parse(ConversationSource $source, ?Options $options = null): Log
    {
        $mode = $options !== null ? $options->strictMode : StrictMode::Loud;

        return match (true) {
            $source instanceof Sessions => $this->parseSessions($source, $mode),
            $source instanceof History  => $this->parseHistory($source, $mode),
            $source instanceof Sqlite   => $this->parseSqlite($source, $mode),
            $source instanceof All      => $this->parseAll($source, $mode),
            default => throw new \InvalidArgumentException(
                sprintf(
                    '%s does not support source type %s',
                    self::class,
                    $source::class,
                ),
            ),
        };
    }

    /**
     * Convert a decoded JSONL/SQLite row into one or more Record instances.
     *
     * @param array<string, mixed> $row
     * @return \Generator<\Phalanx\Panoply\Conversation\Record>
     */
    private static function rowToRecords(array $row, int $seq, StrictMode $mode): \Generator
    {
        $type    = isset($row['type']) && is_string($row['type']) ? $row['type'] : '';
        $role    = isset($row['role']) && is_string($row['role']) ? $row['role'] : '';
        $content = isset($row['content']) && is_string($row['content']) ? $row['content'] : '';
        $ts      = self::extractTimestamp($row);
        $id      = Id::generate();

        switch ($type) {
            case 'message':
                if ($role === '') {
                    $role = 'user';
                }
                yield new Message($id, $seq, $ts, role: $role, text: $content);
                break;

            case 'tool_call':
                $toolName = isset($row['tool_name']) && is_string($row['tool_name'])
                    ? $row['tool_name'] : 'unknown_tool';
                $callId   = isset($row['call_id']) && is_string($row['call_id'])
                    ? $row['call_id'] : Id::generate();
                $arguments = [];

                if ($content !== '') {
                    $decoded = json_decode($content, associative: true);
                    if (is_array($decoded)) {
                        $arguments = $decoded;
                    }
                }

                yield new ToolCall(
                    $id,
                    $seq,
                    $ts,
                    callId: $callId,
                    toolName: $toolName,
                    arguments: $arguments,
                );
                break;

            case 'tool_result':
                $callId  = isset($row['call_id']) && is_string($row['call_id']) ? $row['call_id'] : '';
                $isError = isset($row['is_error']) && (bool) $row['is_error'];
                yield new ToolResult(
                    $id,
                    $seq,
                    $ts,
                    callId: $callId,
                    output: $content,
                    isError: $isError,
                );
                break;

            default:
                switch ($mode) {
                    case StrictMode::Loud:
                        throw new \UnexpectedValueException(
                            "Codex parser: unrecognised record type '{$type}'",
                        );
                    case StrictMode::Lenient:
                        $rawJson = json_encode($row) ?: $type;
                        yield new Unknown($id, $seq, $ts, rawJson: $rawJson, parserHint: $type);
                        break;
                    case StrictMode::Silent:
                        break;
                }
                break;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function extractTimestamp(array $row): \DateTimeImmutable
    {
        $ts = $row['ts'] ?? $row['timestamp'] ?? null;

        if (is_int($ts) || (is_string($ts) && ctype_digit($ts))) {
            return new \DateTimeImmutable()->setTimestamp((int) $ts);
        }

        if (is_string($ts) && $ts !== '') {
            $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $ts)
                ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $ts)
                ?: false;

            if ($dt !== false) {
                return $dt;
            }
        }

        return new \DateTimeImmutable();
    }

    private function parseSessions(Sessions $source, StrictMode $mode): Log
    {
        $sessionsDir = $source->sessionsDir;

        return new Log(static function () use ($sessionsDir, $mode): \Generator {
            if (!is_dir($sessionsDir)) {
                return;
            }

            $outerIter = new \RecursiveDirectoryIterator(
                $sessionsDir,
                \FilesystemIterator::SKIP_DOTS,
            );
            $innerIter = new \RecursiveIteratorIterator($outerIter);

            $seq = 0;

            /** @var \SplFileInfo $file */
            foreach ($innerIter as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'jsonl') {
                    continue;
                }

                foreach (JsonlReader::read($file->getPathname()) as $row) {
                    $seq++;
                    yield from self::rowToRecords($row, $seq, $mode);
                }
            }
        });
    }

    private function parseHistory(History $source, StrictMode $mode): Log
    {
        $path = $source->historyJsonlPath;

        return new Log(static function () use ($path, $mode): \Generator {
            $seq = 0;
            foreach (JsonlReader::read($path) as $row) {
                $seq++;
                yield from self::rowToRecords($row, $seq, $mode);
            }
        });
    }

    private function parseSqlite(Sqlite $source, StrictMode $mode): Log
    {
        $path = $source->sqlitePath;

        return new Log(static function () use ($path, $mode): \Generator {
            $seq = 0;
            foreach (SqliteReader::read($path) as $row) {
                $seq++;
                yield from self::rowToRecords($row, $seq, $mode);
            }
        });
    }

    private function parseAll(All $source, StrictMode $mode): Log
    {
        // Build individual logs for present sources.
        $sessionLog = null;
        if ($source->sessions !== null) {
            $sessionLog = $this->parseSessions($source->sessions, $mode);
        }

        $historyLog = null;
        if ($source->history !== null) {
            $historyLog = $this->parseHistory($source->history, $mode);
        }

        $sqliteLog = null;
        if ($source->sqlite !== null) {
            try {
                $sqliteLog = $this->parseSqlite($source->sqlite, $mode);
            } catch (SqliteUnavailable) {
                // SQLite extension absent — omit from merge silently.
            }
        }

        // Timestamp key: pull $record->at as Unix timestamp for ordering.
        $tsKey = static fn (\Phalanx\Panoply\Conversation\Record $r): int =>
            $r->at->getTimestamp();

        // Dedup key: canonical JSON payload hash across all three sources.
        $dedupKey = static fn (\Phalanx\Panoply\Conversation\Record $r): string =>
            json_encode($r->toCanonical()['payload']) ?: '';

        // Build the merge chain starting from whichever log is available.
        $logs = array_filter([$sessionLog, $historyLog, $sqliteLog]);

        if (count($logs) === 0) {
            return Log::from([]);
        }

        $logs  = array_values($logs);
        $first = array_shift($logs);

        /** @var Log $first */
        /** @var list<Log> $logs */
        return $first->interleaveByDedup($tsKey, $dedupKey, ...$logs);
    }
}
