<?php

declare(strict_types=1);

namespace Phalanx\Athena\Event;

final class TokenDelta
{
    public function __construct(
        private(set) ?string $text = null,
        private(set) ?string $toolCallId = null,
        private(set) ?string $toolName = null,
        private(set) ?string $toolInputJson = null,
    ) {
    }
}
