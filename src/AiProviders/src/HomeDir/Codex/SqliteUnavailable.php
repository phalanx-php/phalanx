<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\HomeDir\Codex;

/**
 * Thrown by {@see SqliteReader} when the `sqlite3` PHP extension is absent.
 * Callers that care about graceful degradation should catch this specific
 * type and fall back to JSONL-only sources. {@see Source\All} catches this
 * internally and omits the SQLite source from the merge.
 *
 * Final — exception identity must be exact for callers to catch reliably.
 */
final class SqliteUnavailable extends \RuntimeException
{
    public static function extensionMissing(): self
    {
        return new self(
            "The 'sqlite3' PHP extension is required to read Codex SQLite databases. " .
            "Install the extension or fall back to JSONL sources only. " .
            "Add 'ext-sqlite3' to your project's composer.json suggest section " .
            "if you want to silence this error.",
        );
    }
}
