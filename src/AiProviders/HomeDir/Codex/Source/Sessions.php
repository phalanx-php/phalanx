<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\HomeDir\Codex\Source;

use Phalanx\AiProviders\Conversation\Source as ConversationSource;

/**
 * Conversation source over the Codex sessions directory tree
 * (`~/.codex/sessions/<year>/<date>/*.jsonl`). Each JSONL file in the tree
 * is a distinct session recording.
 *
 * Final — Source subclasses are sealed per variant.
 */
final class Sessions extends ConversationSource
{
    public function __construct(
        private(set) string $sessionsDir,
    ) {
    }
}
