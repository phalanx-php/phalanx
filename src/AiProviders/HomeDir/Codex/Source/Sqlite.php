<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\HomeDir\Codex\Source;

use Phalanx\AiProviders\Conversation\Source as ConversationSource;

/**
 * Conversation source for Codex's SQLite database
 * (`~/.codex/logs_2.sqlite`). Reading requires `ext-sqlite3`; when absent
 * {@see \Phalanx\AiProviders\HomeDir\Codex\SqliteReader::read()} throws
 * {@see \Phalanx\AiProviders\HomeDir\Codex\SqliteUnavailable}, which
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
