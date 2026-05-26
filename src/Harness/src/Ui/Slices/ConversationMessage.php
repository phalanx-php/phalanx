<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

use DateTimeImmutable;
use InvalidArgumentException;

final class ConversationMessage
{
    /**
     * @param 'message'|'thinking'|null $channel null for user messages
     */
    public function __construct(
        private(set) string $role,
        private(set) string $text,
        private(set) ?string $channel,
        private(set) bool $complete,
        private(set) DateTimeImmutable $at,
    ) {
        if ($role !== 'user' && $role !== 'assistant') {
            throw new InvalidArgumentException(
                sprintf('Role must be "user" or "assistant", got "%s".', $role),
            );
        }
    }
}
