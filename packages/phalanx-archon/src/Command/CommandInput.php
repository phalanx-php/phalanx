<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

/**
 * Parsed argv split into the two halves the command body cares about:
 * positional args (CommandArgs) and named options (CommandOptions).
 * Constructed by InputParser during dispatch and exposed on CommandScope
 * via $scope->args / $scope->options.
 */
final class CommandInput
{
    public function __construct(
        private(set) CommandArgs $args,
        private(set) CommandOptions $options,
    ) {
    }
}
