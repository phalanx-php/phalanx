<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Throwable;

interface ConsoleErrorRenderer
{
    public function render(CommandContext $ctx, Throwable $e, StreamOutput $output): bool;
}
