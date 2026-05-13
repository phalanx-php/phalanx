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

    public function reset(?string $text = null, ?string $toolCallId = null, ?string $toolName = null, ?string $toolInputJson = null): void
    {
        $this->text = $text;
        $this->toolCallId = $toolCallId;
        $this->toolName = $toolName;
        $this->toolInputJson = $toolInputJson;
    }
}
