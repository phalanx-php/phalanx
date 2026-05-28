<?php

declare(strict_types=1);

namespace BgAgents\Repl;

use BgAgents\Config\BgAgentsConfig;
use BgAgents\Daemon8\ObservationClient;
use BgAgents\Repl\Cmd\AskCmd;
use BgAgents\Repl\Cmd\BookkeeperAcceptCmd;
use BgAgents\Repl\Cmd\BookkeeperDismissCmd;
use BgAgents\Repl\Cmd\BookkeeperListCmd;
use BgAgents\Repl\Cmd\EmptyCmd;
use BgAgents\Repl\Cmd\ExitCmd;
use BgAgents\Repl\Cmd\HelpCmd;
use BgAgents\Repl\Cmd\ListSpecialistsCmd;
use BgAgents\Repl\Cmd\MemoryQueryCmd;
use BgAgents\Repl\Cmd\StatusCmd;
use BgAgents\Repl\Cmd\UnknownCmd;
use BgAgents\Memory\MemoryStore;
use BgAgents\Repl\Handler\AskHandler;
use BgAgents\Repl\Handler\BookkeeperHandler;
use BgAgents\Repl\Handler\HelpHandler;
use BgAgents\Repl\Handler\ListSpecialistsHandler;
use BgAgents\Repl\Handler\MemoryHandler;
use BgAgents\Repl\Handler\StatusHandler;
use BgAgents\Specialist\SpecialistRegistry;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\ExecutionScope;

/**
 * Dispatches a parsed ReplCommand to the right handler.
 *
 * Handlers are constructed lazily from $scope->service() — this sidesteps
 * the container's topological dep ordering on multi-arg factories. The
 * dispatcher itself stays cheap and only needs the printer for its own
 * fall-through paths.
 */
final readonly class ReplDispatcher
{
    public function __construct(
        public ReplPrinter $printer,
    ) {}

    public function dispatch(ReplCommand $cmd, ExecutionScope $scope): bool
    {
        if ($cmd instanceof ExitCmd) {
            return false;
        }

        match (true) {
            $cmd instanceof EmptyCmd => null,
            $cmd instanceof HelpCmd => self::makeHelp($scope)(),
            $cmd instanceof ListSpecialistsCmd => self::makeList($scope)(),
            $cmd instanceof StatusCmd => self::makeStatus($scope)->handle($scope),
            $cmd instanceof AskCmd => self::makeAsk($scope)->handle($cmd, $scope),
            $cmd instanceof BookkeeperListCmd => self::makeBookkeeper($scope)->list($scope),
            $cmd instanceof BookkeeperAcceptCmd => self::makeBookkeeper($scope)->accept($scope, $cmd->issueId),
            $cmd instanceof BookkeeperDismissCmd => self::makeBookkeeper($scope)->dismiss($scope, $cmd->issueId),
            $cmd instanceof MemoryQueryCmd => self::makeMemory($scope)->query($scope, $cmd->topic),
            $cmd instanceof UnknownCmd => $this->printer->error("unknown command: {$cmd->raw}; type help"),
            default => $this->printer->error('internal: unhandled repl command kind'),
        };

        return true;
    }

    private static function makeHelp(ExecutionScope $scope): HelpHandler
    {
        return new HelpHandler($scope->service(ReplPrinter::class));
    }

    private static function makeList(ExecutionScope $scope): ListSpecialistsHandler
    {
        return new ListSpecialistsHandler(
            $scope->service(SpecialistRegistry::class),
            $scope->service(ReplPrinter::class),
        );
    }

    private static function makeStatus(ExecutionScope $scope): StatusHandler
    {
        return new StatusHandler(
            $scope->service(ObservationClient::class),
            $scope->service(BgAgentsConfig::class),
            $scope->service(SpecialistRegistry::class),
            $scope->service(ReplPrinter::class),
        );
    }

    private static function makeAsk(ExecutionScope $scope): AskHandler
    {
        return new AskHandler(
            $scope->service(SpecialistRegistry::class),
            $scope->service(ReplPrinter::class),
        );
    }

    private static function makeBookkeeper(ExecutionScope $scope): BookkeeperHandler
    {
        return new BookkeeperHandler(
            $scope->service(ObservationClient::class),
            $scope->service(SwarmBus::class),
            $scope->service(SwarmConfig::class),
            $scope->service(ReplPrinter::class),
            $scope->service(MemoryStore::class),
        );
    }

    private static function makeMemory(ExecutionScope $scope): MemoryHandler
    {
        return new MemoryHandler(
            $scope->service(MemoryStore::class),
            $scope->service(ReplPrinter::class),
        );
    }
}
