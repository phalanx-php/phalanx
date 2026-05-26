<?php

declare(strict_types=1);

namespace Phalanx\Themis;

final readonly class ConfigEntry
{
    public function __construct(
        public string $parameter,
        public string $envKey,
        public string $type,
        public bool $required,
        public ?string $default = null,
        public ?string $description = null,
        public bool $secret = false,
        public ?string $group = null,
        public ?string $example = null,
    ) {
    }
}
