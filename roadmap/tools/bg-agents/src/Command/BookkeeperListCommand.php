<?php

declare(strict_types=1);

namespace BgAgents\Command;

use BgAgents\Daemon8\ObservationClient;
use BgAgents\Memory\MemoryStore;
use BgAgents\Repl\Handler\BookkeeperHandler;
use BgAgents\Repl\ReplPrinter;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * One-shot variant of `bookkeeper list` — useful from another process or in
 * scripts. Reads pending issues from the daemon8 blackboard.
 */
final class BookkeeperListCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        $handler = new BookkeeperHandler(
            $scope->service(ObservationClient::class),
            $scope->service(SwarmBus::class),
            $scope->service(SwarmConfig::class),
            $scope->service(ReplPrinter::class),
            $scope->service(MemoryStore::class),
        );
        $handler->list($scope);
        return 0;
    }
}
