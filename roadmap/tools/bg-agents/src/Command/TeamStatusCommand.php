<?php

declare(strict_types=1);

namespace BgAgents\Command;

use BgAgents\Config\BgAgentsConfig;
use BgAgents\Daemon8\ObservationClient;
use BgAgents\Repl\Handler\StatusHandler;
use BgAgents\Repl\ReplPrinter;
use BgAgents\Specialist\SpecialistRegistry;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * One-shot variant of the REPL `status` command. Reads daemon8 to find the
 * latest team.heartbeat — works even if the team is running in a separate
 * process, since the source of truth is the observation stream.
 */
final class TeamStatusCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        $handler = new StatusHandler(
            $scope->service(ObservationClient::class),
            $scope->service(BgAgentsConfig::class),
            $scope->service(SpecialistRegistry::class),
            $scope->service(ReplPrinter::class),
        );
        $handler->handle($scope);
        return 0;
    }
}
