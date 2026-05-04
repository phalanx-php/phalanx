<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

/**
 * Declarative description of a single named option (--name, -n) on a
 * command. Built via the Opt::flag()/Opt::value() factories and consumed
 * by CommandConfig + InputParser during dispatch.
 */
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
