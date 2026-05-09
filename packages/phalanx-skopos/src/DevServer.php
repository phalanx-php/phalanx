<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

use Closure;
use OpenSwoole\Coroutine\Channel;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Skopos\LiveReload\BroadcasterChannel;
use Phalanx\Skopos\LiveReload\Server as LiveReloadServer;
use Phalanx\Skopos\Output\Multiplexer;
use Phalanx\Support\SignalHandler;
use Phalanx\Task\Executable;
use Throwable;

/**
 * Runtime driver dispatched by SkoposApplicationBuilder onto the Aegis
 * root scope. Owns the long-running supervisor coroutines: managed
 * processes, file watchers, the LiveReload SSE server, and signal-driven
 * shutdown.
 *
 * The shutdown channel is the single rendezvous point for all exit
 * conditions: signal, server crash, or external scope cancellation. Once
 * triggered, cleanup runs synchronously in __invoke before returning the
 * exit code, then control returns through Application::run() which
 * disposes the root scope (which in turn closes any still-open Streaming
 * Process handles via onDispose hooks).
 */
final class DevServer implements Executable
{
    /** @param list<Process> $processes */
    public function __construct(
        private readonly array $processes,
        private readonly ?int $liveReloadPort = null,
        private readonly bool $quiet = false,
    ) {
    }

    public function __invoke(ExecutionScope $scope): int
    {
        $multiplexer = new Multiplexer();
        $broadcaster = new BroadcasterChannel();
        $managed = self::buildManagedProcesses($this->processes);

        $reloadServer = $this->startLiveReload($scope, $broadcaster, $multiplexer);

        if (!$this->quiet) {
            self::printProcessTable($managed, $multiplexer);
        }

        foreach ($managed as $mp) {
            $mp->start($scope, $multiplexer);
        }

        try {
            foreach ($managed as $mp) {
                $mp->waitUntilReady($scope, timeout: 30.0);
            }
        } catch (Cancelled $e) {
            $reloadServer?->stop();
            self::shutdownProcesses($managed, $multiplexer, $this->quiet);
            throw $e;
        } catch (Throwable $e) {
            $multiplexer->writeLine("\033[31m[skopos] Readiness failed: {$e->getMessage()}\033[0m");
            $reloadServer?->stop();
            self::shutdownProcesses($managed, $multiplexer, $this->quiet);
            return 1;
        }

        if (!$this->quiet) {
            $multiplexer->writeLine('');
            $multiplexer->writeLine("\033[1;32m[skopos] All processes ready.\033[0m");
            $multiplexer->writeLine('');
        }

        if ($reloadServer !== null) {
            self::wireReloadCallbacks($managed, $multiplexer, $broadcaster);
        }

        $shutdownChannel = new Channel(1);

        $this->startWatchers($scope, $managed, $multiplexer, $broadcaster);
        self::wireServerCrashWatchdog($managed, $multiplexer, $shutdownChannel);

        SignalHandler::register(static function () use ($shutdownChannel): void {
            $shutdownChannel->push('signal');
        });

        $scope->go(static function () use ($scope, $shutdownChannel): void {
            while (!$scope->isCancelled) {
                $scope->delay(0.1);
            }
            $shutdownChannel->push('cancelled');
        }, name: 'skopos.shutdown.cancellation-watch');

        $shutdownChannel->pop();

        if (!$this->quiet) {
            $multiplexer->writeLine('');
            $multiplexer->writeLine("\033[33m[skopos] Shutting down...\033[0m");
        }

        $reloadServer?->stop();
        self::shutdownProcesses($managed, $multiplexer, $this->quiet);

        if (!$this->quiet) {
            $multiplexer->writeLine("\033[33m[skopos] All processes stopped.\033[0m");
        }

        return 0;
    }

    /**
     * @param list<Process> $configs
     * @return list<ManagedProcess>
     */
    private static function buildManagedProcesses(array $configs): array
    {
        return array_map(
            static fn(Process $config): ManagedProcess => new ManagedProcess($config),
            $configs,
        );
    }

    /** @param list<ManagedProcess> $managed */
    private static function printProcessTable(array $managed, Multiplexer $multiplexer): void
    {
        $multiplexer->writeLine("\033[1m[skopos] Starting processes:\033[0m");

        foreach ($managed as $mp) {
            $multiplexer->writeLine(
                sprintf("  \033[36m%-20s\033[0m %s", $mp->config->name, $mp->config->command)
            );
        }

        $multiplexer->writeLine('');
    }

    /** @param list<ManagedProcess> $managed */
    private static function wireReloadCallbacks(
        array $managed,
        Multiplexer $multiplexer,
        BroadcasterChannel $broadcaster,
    ): void {
        foreach ($managed as $mp) {
            if ($mp->config->reloadProbe === null) {
                continue;
            }

            $name = $mp->config->name;

            $mp->onOutput(static function (string $line) use ($name, $multiplexer, $broadcaster): void {
                $multiplexer->writeLine("\033[2m[skopos] Reload triggered by {$name}: {$line}\033[0m");
                $broadcaster->reload();
            });
        }
    }

    /**
     * @param list<ManagedProcess> $managed
     * @param Channel $shutdownChannel
     */
    private static function wireServerCrashWatchdog(
        array $managed,
        Multiplexer $multiplexer,
        Channel $shutdownChannel,
    ): void {
        foreach ($managed as $mp) {
            if (!$mp->config->isServer) {
                continue;
            }

            $name = $mp->config->name;

            $mp->onCrash(static function () use ($name, $multiplexer, $shutdownChannel): void {
                $multiplexer->writeLine(
                    "\033[31m[skopos] Server process '{$name}' crashed. Shutting down.\033[0m"
                );
                $shutdownChannel->push('crash');
            });
        }
    }

    /** @param list<ManagedProcess> $managed */
    private static function shutdownProcesses(array $managed, Multiplexer $multiplexer, bool $quiet): void
    {
        foreach ($managed as $mp) {
            $mp->stop();
            if (!$quiet) {
                $multiplexer->writeLine("\033[2m[skopos] {$mp->config->name} stopped.\033[0m");
            }
        }
    }

    private static function makeWatchCallback(
        ManagedProcess $mp,
        string $name,
        Multiplexer $multiplexer,
        BroadcasterChannel $broadcaster,
        ExecutionScope $scope,
    ): Closure {
        return static function (array $changed) use ($mp, $name, $multiplexer, $broadcaster, $scope): void {
            $short = array_map(static fn(string $path): string => basename($path), $changed);
            $label = count($short) <= 3
                ? implode(', ', $short)
                : implode(', ', array_slice($short, 0, 3)) . ' +' . (count($short) - 3) . ' more';

            $multiplexer->writeLine(
                "\033[33m[skopos] Change detected ({$label}). Restarting {$name}...\033[0m"
            );

            try {
                $mp->restart($scope, $multiplexer);
                $multiplexer->writeLine("\033[32m[skopos] {$name} restarted.\033[0m");
                $broadcaster->reload();
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $multiplexer->writeLine("\033[31m[skopos] Restart failed for {$name}: {$e->getMessage()}\033[0m");
            }
        };
    }

    private function startLiveReload(
        ExecutionScope $scope,
        BroadcasterChannel $broadcaster,
        Multiplexer $multiplexer,
    ): ?LiveReloadServer {
        if ($this->liveReloadPort === null) {
            return null;
        }

        $server = new LiveReloadServer($this->liveReloadPort, $broadcaster);
        $port = $this->liveReloadPort;

        $scope->go(static function () use ($server, $scope): void {
            try {
                $server->start($scope);
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                if (!$server->isStopping) {
                    throw $e;
                }
            }
        }, name: 'skopos.livereload.server');

        if (!$this->quiet) {
            $multiplexer->writeLine("\033[2m[skopos] Live reload server on port {$port}\033[0m");
        }

        return $server;
    }

    /** @param list<ManagedProcess> $managed */
    private function startWatchers(
        ExecutionScope $scope,
        array $managed,
        Multiplexer $multiplexer,
        BroadcasterChannel $broadcaster,
    ): void {
        foreach ($managed as $mp) {
            if ($mp->config->watchPaths === []) {
                continue;
            }

            $name = $mp->config->name;
            $cwdValue = $mp->config->cwd;
            if ($cwdValue === null) {
                $detected = getcwd();
                $cwdValue = $detected === false ? null : $detected;
            }

            if (!$this->quiet) {
                $exts = implode(
                    ', ',
                    array_map(static fn(string $ext): string => '.' . $ext, $mp->config->watchExtensions),
                );
                $multiplexer->writeLine(
                    "\033[2m[skopos] Watching {$name}: " . implode(', ', $mp->config->watchPaths) . " [{$exts}]\033[0m"
                );
            }

            $watcher = new FileWatcher(
                $mp->config->watchPaths,
                $mp->config->watchExtensions,
                self::makeWatchCallback($mp, $name, $multiplexer, $broadcaster, $scope),
                cwd: $cwdValue,
            );

            $watcher->start($scope);
        }
    }
}
