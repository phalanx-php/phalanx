<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\Codex\Source;

use Phalanx\Panoply\Conversation\Source as ConversationSource;

/**
 * Composite source that merges all three Codex data sources — sessions,
 * history, and SQLite — into one chronologically ordered, deduplicated
 * stream. Each individual source is optional; when `null` it is omitted from
 * the merge.
 *
 * The `availableSources` list is determined at construction time from which
 * sources are non-null. It reflects "which sources were configured" — not
 * "which sources successfully yielded records". A SQLite source that is
 * configured but fails at runtime because `ext-sqlite3` is absent is still
 * reported here; {@see Parser} catches the resulting {@see SqliteUnavailable}
 * and silently omits that source from the merge.
 *
 * Merge strategy:
 * - Key: Unix timestamp (ascending sort).
 * - Dedup key: `raw_hash` field from each normalized row. Records with the
 *   same `raw_hash` are yielded only once, keeping the first occurrence
 *   across all sources.
 *
 * Final — Source subclasses are sealed per variant.
 */
final class All extends ConversationSource
{
    /** @var list<string> */
    private(set) array $availableSources;

    public function __construct(
        private(set) ?Sessions $sessions,
        private(set) ?History $history,
        private(set) ?Sqlite $sqlite,
    ) {
        $this->availableSources = array_keys(array_filter([
            'sessions' => $sessions !== null,
            'history' => $history !== null,
            'sqlite' => $sqlite !== null,
        ]));
    }

    /**
     * Returns which sources were configured at construction. Values are one or
     * more of `"sessions"`, `"history"`, `"sqlite"`. A source that appears here
     * but encounters a runtime error (e.g. missing `ext-sqlite3` for `"sqlite"`)
     * will be silently omitted from the merge by {@see Parser::parseAll()}.
     *
     * @return list<string>
     */
    public function availableSources(): array
    {
        return $this->availableSources;
    }
}
