<?php

declare(strict_types=1);

namespace Phalanx\Console\Console;

use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Console\Output\StreamOutput;
use Throwable;

interface ConsoleErrorRenderer
{
    public function render(CommandContext $ctx, Throwable $e, StreamOutput $output): bool;
}
