<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Throwable;

/**
 * Contract for rendering an exception to the terminal.
 *
 * This allows for rich, structured error output that can include
 * task trees, stack traces, or context-specific hints.
 */
interface ConsoleErrorRenderer
{
    /**
     * Renders the exception to the given output.
     *
     * @param CommandScope $scope The CLI command scope.
     * @param Throwable $e The exception that occurred.
     * @param StreamOutput $output The terminal output stream.
     * @return bool True if the error was handled and rendered, false to delegate.
     */
    public function render(CommandScope $scope, Throwable $e, StreamOutput $output): bool;
}
