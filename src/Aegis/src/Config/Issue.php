<?php

declare(strict_types=1);

namespace Phalanx\Config;

final readonly class Issue
{
    public function __construct(
        public IssueLevel $level,
        public string $code,
        public string $message,
        public ?string $envKey = null,
        public ?string $path = null,
        public ?string $hint = null,
    ) {
    }
}
