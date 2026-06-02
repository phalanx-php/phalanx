<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

/**
 * Definition of a command argument.
 */
final class CommandArgument
{
    public function __construct(
        private(set) string $name,
        private(set) string $description = '',
        private(set) bool $required = true,
        private(set) mixed $default = null,
    ) {
    }
}
