<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Skopos\Output\Multiplexer;
use Phalanx\Support\SignalHandler;
use Phalanx\Task\Executable;
use Throwable;

/**
 * Runtime driver dispatched by SkoposApplicationBuilder onto the Aegis
 * root scope. Owns the long-running supervisor coroutines: managed
 * processes, file watchers, and signal-driven shutdown.
 *
 * Shutdown rendezvous is the parent scope's cancellation token. Three
 * sources can trigger it:
 *
 *   1. SIGINT/SIGTERM via {@see SignalHandler}
 *   2. Crash callback on a server-class managed process
 *   3. External cancellation propagated from above
 *
 * The main task waits in a `$scope->delay()` loop that exits cleanly
 * when the cancellation token cancels — either on the next iteration's
 * loop check or via `Cancelled` thrown from inside `delay()`. Cleanup
 * runs synchronously after that: `$mp->stop()` does not suspend, and
 * the Aegis scope dispose (after this method returns) re-runs the
 * shutdown via the `onDispose` hooks `StreamingProcessHandle` registers
 * for each managed process — both shutdown paths are idempotent.
 *
 * LiveReload (SSE broadcast channel on file change) is intentionally
 * not implemented at this layer: OpenSwoole 26.2 does not ship a
 * coroutine-mode HTTP server and Skopos refuses to embed raw OpenSwoole
 * HTTP server constructs without Aegis lifecycle wrapping. The feature
 * returns when a Stoa coroutine runner lands. File watchers still
 * trigger process restarts in the meantime.
 */
final class DevServer implements Executable
{
    /** @param list<Process> $processes */
    public function __construct(
        private array $processes,
        private bool $quiet = false,
    ) {
    }

    public function __invoke(ExecutionScope $scope): int
    {
        $multiplexer = new Multiplexer();
        $managed = self::buildManagedProcesses($this->processes);

        /**
         * Register before readiness waits so SIGINT flows through the
         * cancellation token instead of the default OS handler.
         */
        SignalHandler::register(static function () use ($scope): void {
            if (!$scope->isCancelled) {
                $scope->cancellation()->cancel();
            }
        });

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
            self::shutdownProcesses($managed, $multiplexer, $this->quiet);
            throw $e;
        } catch (Throwable $e) {
            $multiplexer->writeLine("\033[31m[skopos] Readiness failed: {$e->getMessage()}\033[0m");
            self::shutdownProcesses($managed, $multiplexer, $this->quiet);
            return 1;
        }

        if (!$this->quiet) {
            $multiplexer->writeLine('');
            $multiplexer->writeLine("\033[1;32m[skopos] All processes ready.\033[0m");
            $multiplexer->writeLine('');
        }

        $this->startWatchers($scope, $managed, $multiplexer);
        self::wireServerCrashWatchdog($managed, $multiplexer, $scope);

        try {
            while (!$scope->isCancelled) {
                $scope->delay(0.5);
            }
        } catch (Cancelled) {
        }

        if (!$this->quiet) {
            $multiplexer->writeLine('');
            $multiplexer->writeLine("\033[33m[skopos] Shutting down...\033[0m");
        }

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
    private static function wireServerCrashWatchdog(
        array $managed,
        Multiplexer $multiplexer,
        ExecutionScope $scope,
    ): void {
        foreach ($managed as $mp) {
            if (!$mp->config->isServer) {
                continue;
            }

            $name = $mp->config->name;

            $mp->onCrash(static function () use ($name, $multiplexer, $scope): void {
                $multiplexer->writeLine(
                    "\033[31m[skopos] Server process '{$name}' crashed. Shutting down.\033[0m"
                );
                if (!$scope->isCancelled) {
                    $scope->cancellation()->cancel();
                }
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
        ExecutionScope $scope,
    ): Closure {
        return static function (array $changed) use ($mp, $name, $multiplexer, $scope): void {
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
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $multiplexer->writeLine("\033[31m[skopos] Restart failed for {$name}: {$e->getMessage()}\033[0m");
            }
        };
    }

    /** @param list<ManagedProcess> $managed */
    private function startWatchers(
        ExecutionScope $scope,
        array $managed,
        Multiplexer $multiplexer,
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
                self::makeWatchCallback($mp, $name, $multiplexer, $scope),
                cwd: $cwdValue,
            );

            $watcher->start($scope);
        }
    }
}
