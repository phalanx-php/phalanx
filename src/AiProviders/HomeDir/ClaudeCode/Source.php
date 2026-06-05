<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\HomeDir\ClaudeCode;

use Phalanx\AiProviders\Conversation\Source as ConversationSource;

/**
 * Conversation source for a single Claude Code JSONL conversation file.
 * The `path` is the absolute path to one `.jsonl` file inside a Claude Code
 * project directory (e.g. `~/.claude/projects/-Users-jhavens-sparta/abc.jsonl`).
 *
 * Final — Source subclasses are sealed per vendor; their payload shape is
 * tightly coupled to the matching {@see Parser}.
 */
final class Source extends ConversationSource
{
    public function __construct(
        private(set) string $path,
    ) {
    }
}
