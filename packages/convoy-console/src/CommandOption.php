<?php

declare(strict_types=1);

namespace Convoy\Console;

final class CommandOption
{
    public function __construct(
        public private(set) string $name,
        public private(set) string $shorthand = '',
        public private(set) string $description = '',
        public private(set) bool $requiresValue = false,
        public private(set) mixed $default = null,
    ) {
    }
}
