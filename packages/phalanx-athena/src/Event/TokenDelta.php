<?php

declare(strict_types=1);

namespace Phalanx\Athena\Event;

final readonly class TokenDelta
{
    public function __construct(
        public ?string $text = null,
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public ?string $toolInputJson = null,
    ) {}
}
