<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Traceable;

/** @internal */
final class CommandInvocation implements Executable, Traceable
{
    public string $traceName {
        get => $this->trace;
    }

    /** @param list<string> $args */
    private function __construct(
        private CommandGroup|InlineCommand $target,
        private string $command,
        private array $args,
        private string $resourceId,
        private string $trace,
    ) {
    }

    /** @param list<string> $args */
    public static function group(CommandGroup $group, string $command, array $args, string $resourceId): self
    {
        return new self($group, $command, $args, $resourceId, "archon.command.$command");
    }

    /** @param list<string> $args */
    public static function inline(InlineCommand $command, array $args, string $resourceId): self
    {
        return new self($command, $command->traceName, $args, $resourceId, $command->traceName);
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        if ($this->target instanceof InlineCommand) {
            return $this->target->dispatch($scope, $this->args, $this->resourceId);
        }

        return $this->target->dispatch($scope, $this->command, $this->args, $this->resourceId);
    }
}
