<?php

declare(strict_types=1);

namespace Convoy\Console;

/**
 * Definition of a command argument.
 */
final class CommandArgument
{
    public function __construct(
        public private(set) string $name,
        public private(set) string $description = '',
        public private(set) bool $required = true,
        public private(set) mixed $default = null,
    ) {
    }
}
