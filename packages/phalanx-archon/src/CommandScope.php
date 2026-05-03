<?php

declare(strict_types=1);

namespace Phalanx\Archon;

use Phalanx\Scope\ExecutionScope;

interface CommandScope extends ExecutionScope
{
    public string $commandName { get; }
    public string $commandResourceId { get; }
    public CommandArgs $args { get; }
    public CommandOptions $options { get; }
    public CommandConfig $config { get; }
}
