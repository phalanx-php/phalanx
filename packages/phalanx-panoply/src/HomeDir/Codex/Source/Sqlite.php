<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\Codex\Source;

use Phalanx\Panoply\Conversation\Source as ConversationSource;

/**
 * Conversation source for Codex's SQLite database
 * (`~/.codex/logs_2.sqlite`). Reading requires `ext-sqlite3`; when absent
 * {@see \Phalanx\Panoply\HomeDir\Codex\SqliteReader::read()} throws
 * {@see \Phalanx\Panoply\HomeDir\Codex\SqliteUnavailable}, which
 * {@see All} catches and reports via {@see All::configuredSources()}.
 *
 * Final — Source subclasses are sealed per variant.
 */
final class Sqlite extends ConversationSource
{
    public function __construct(
        private(set) string $sqlitePath,
    ) {
    }
}
