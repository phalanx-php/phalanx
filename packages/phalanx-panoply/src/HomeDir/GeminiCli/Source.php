<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\GeminiCli;

use Phalanx\Panoply\Conversation\Source as ConversationSource;

/**
 * Conversation source for a single Gemini CLI JSONL conversation file.
 * The `path` is the absolute path to one `.jsonl` file inside a Gemini CLI
 * history directory (e.g. `~/.gemini/history/<project_id>/abc.jsonl`).
 *
 * Final — Source subclasses are sealed per vendor.
 */
final class Source extends ConversationSource
{
    public function __construct(
        private(set) string $path,
    ) {
    }
}
