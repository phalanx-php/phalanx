<?php

declare(strict_types=1);

namespace BgAgents\Repl;

use BgAgents\Config\BgAgentsConfig;
use BgAgents\Specialist\SpecialistRegistry;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use React\EventLoop\Loop;

/**
 * The interactive front-end. Long-lived: blocks until the user types `exit`
 * or stdin closes. Each command runs in a derived scope that's disposed
 * before the next prompt to avoid leaking provider cleanup callbacks across
 * turns (the long-running-session footgun called out in Phalanx CLAUDE.md).
 */
final class ReplRunner implements Executable
{
    public function __invoke(ExecutionScope $scope): int
    {
        $printer = $scope->service(ReplPrinter::class);
        $config = $scope->service(BgAgentsConfig::class);
        $registry = $scope->service(SpecialistRegistry::class);
        $dispatcher = $scope->service(ReplDispatcher::class);
        $parser = new ReplCommandParser();

        self::installShutdownSignals($scope, $printer);
        self::printBanner($printer, $config, $registry);
        $printer->prompt();

        $emitter = ReplLineReader::lines();

        foreach ($emitter($scope) as $line) {
            $cmd = $parser->parse($line);

            $childScope = $scope->withAttribute('repl.line', $line);
            try {
                $continue = $dispatcher->dispatch($cmd, $childScope);
            } finally {
                $childScope->dispose();
            }

            if (!$continue) {
                $printer->info('bye');
                $scope->dispose();
                return 0;
            }

            $printer->prompt();
        }

        $scope->dispose();
        return 0;
    }

    /**
     * Loop::addSignal dispatches at tick boundaries, so the handler runs in
     * fiber-safe context. pcntl_async_signals interrupts mid-fiber and
     * triggers "Cannot switch fibers in current execution context" when the
     * resulting dispose() resolves Deferreds that other fibers are awaiting.
     *
     * The framework-grade primitive (scope-bound $scope->onSignal) is
     * proposed in the daemon8 stream under tags=[framework-proposal,aegis,
     * signals]. Until that lands, this is the bg-agents MVP path.
     */
    private static function installShutdownSignals(ExecutionScope $scope, ReplPrinter $printer): void
    {
        if (!defined('SIGINT') || !function_exists('pcntl_signal')) {
            return;
        }

        $sigint = static function () use ($scope, $printer): void {
            $printer->info("\nshutting down (SIGINT)");
            Loop::futureTick(static fn() => $scope->dispose());
        };
        $sigterm = static function () use ($scope, $printer): void {
            $printer->info("\nshutting down (SIGTERM)");
            Loop::futureTick(static fn() => $scope->dispose());
        };

        Loop::addSignal(SIGINT, $sigint);
        Loop::addSignal(SIGTERM, $sigterm);

        $scope->onDispose(static function () use ($sigint, $sigterm): void {
            Loop::removeSignal(SIGINT, $sigint);
            Loop::removeSignal(SIGTERM, $sigterm);
        });
    }

    private static function printBanner(ReplPrinter $printer, BgAgentsConfig $config, SpecialistRegistry $registry): void
    {
        $printer->banner('bg-agents');
        $printer->kv('workspace', $config->workspace);
        $printer->kv('session', $config->session);
        $printer->kv('daemon8', $config->daemon8Url);
        $printer->kv('specialists', implode(', ', $registry->names()));
        $printer->note('type help for commands, exit to leave');
    }
}
