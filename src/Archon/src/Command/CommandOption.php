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
        private(set) string $name,
        private(set) string $shorthand = '',
        private(set) string $description = '',
        private(set) bool $requiresValue = false,
        private(set) mixed $default = null,
    ) {
    }
}
