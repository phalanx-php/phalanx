<?php

declare(strict_types=1);

namespace Convoy\Console;

use Convoy\ExecutionScope;

interface CommandScope extends ExecutionScope
{
    public string $commandName { get; }
    public CommandArgs $args { get; }
    public CommandOptions $options { get; }
    public CommandConfig $config { get; }
}
