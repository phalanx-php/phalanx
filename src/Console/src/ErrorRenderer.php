<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Output\StreamOutput;
use Throwable;

interface ErrorRenderer
{
    public function render(CommandContext $ctx, Throwable $e, StreamOutput $output): bool;
}
