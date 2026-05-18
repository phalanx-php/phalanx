<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\Codex;

/**
 * Streaming JSONL reader for Codex conversation files. Each call to
 * {@see self::read()} returns a lazy generator; the file is opened
 * and consumed line-by-line — no full file load.
 *
 * Malformed JSON lines are silently dropped (consistent with the
 * forward-compatibility policy Codex itself follows when reading
 * older conversation formats).
 *
 * CRLF line endings are handled automatically via {@see \SplFileObject}
 * with the `DROP_NEW_LINE` flag.
 *
 * Final — reader implementations are sealed.
 */
final class JsonlReader
{
    private function __construct()
    {
    }

    /**
     * Stream decoded JSON objects from a JSONL file. The generator is lazy;
     * nothing is read until the caller iterates. Malformed lines are dropped.
     *
     * @return \Generator<array<string, mixed>>
     */
    public static function read(string $path): \Generator
    {
        if (!is_file($path)) {
            return;
        }

        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::DROP_NEW_LINE | \SplFileObject::SKIP_EMPTY);

        foreach ($file as $rawLine) {
            if (!is_string($rawLine) || $rawLine === '') {
                continue;
            }

            $trimmed = trim($rawLine);

            if ($trimmed === '') {
                continue;
            }

            $decoded = json_decode($trimmed, associative: true);

            if (!is_array($decoded)) {
                // Malformed line — drop silently.
                continue;
            }

            yield $decoded;
        }

        unset($file);
    }
}
