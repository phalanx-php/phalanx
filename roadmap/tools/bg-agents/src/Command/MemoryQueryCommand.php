<?php

declare(strict_types=1);

namespace BgAgents\Command;

use BgAgents\Memory\MemoryStore;
use BgAgents\Repl\Handler\MemoryHandler;
use BgAgents\Repl\ReplPrinter;
use Phalanx\Archon\CommandContext;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class MemoryQueryCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        assert($scope instanceof CommandContext);

        $topic = (string) $scope->args->get('topic');
        $handler = new MemoryHandler(
            $scope->service(MemoryStore::class),
            $scope->service(ReplPrinter::class),
        );
        $handler->query($scope, $topic);

        return 0;
    }
}
