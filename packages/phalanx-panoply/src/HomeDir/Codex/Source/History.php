<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\Codex\Source;

use Phalanx\Panoply\Conversation\Source as ConversationSource;

/**
 * Conversation source for Codex's single `history.jsonl` file
 * (`~/.codex/history.jsonl`). This file aggregates events from all sessions
 * into one chronological JSONL stream and is therefore the primary dedup
 * target when merging with session files.
 *
 * Final — Source subclasses are sealed per variant.
 */
final class History extends ConversationSource
{
    public function __construct(
        private(set) string $historyJsonlPath,
    ) {
    }
}
