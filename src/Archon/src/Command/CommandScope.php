<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use Phalanx\Scope\ExecutionScope;

/**
 * The scope handed to every command __invoke. Extends ExecutionScope with
 * the parsed CLI surface ($args, $options), the command's identity
 * ($commandName, $commandResourceId), and its declarative configuration.
 * One CommandScope is created per dispatched command and disposed when
 * the command body returns; it owns the managed `archon.command` resource
 * for the duration of the invocation.
 */
interface CommandScope extends ExecutionScope
{
    public string $commandName { get; }
    public string $commandResourceId { get; }
    public CommandArgs $args { get; }
    public CommandOptions $options { get; }
    public CommandConfig $config { get; }
}
