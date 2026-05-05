<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

use Closure;
use Phalanx\Skopos\LiveReload\Server as ReloadServer;
use Phalanx\Skopos\Output\Multiplexer;
use Phalanx\Support\SignalHandler;
use React\EventLoop\Loop;
use React\Stream\WritableResourceStream;

use function React\Promise\all;

final class DevServer
{
    /** @var list<Process> */
    private array $processes = [];
    /** @var list<Backend> */
    private array $backends = [];
    /** @var list<Frontend> */
    private array $frontends = [];
    private ?int $liveReloadPort = null;

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function server(string $command, ?string $ready = null, ?string $cwd = null): self
    {
        $process = Process::named(self::deriveNameFromCommand($command))
            ->command($command)
            ->asServer();

        if ($ready !== null) {
            $process = $process->ready($ready);
        }

        if ($cwd !== null) {
            $process = $process->cwd($cwd);
        }

        return $this->process($process);
    }

    public function process(Process $process): self
    {
        $clone = clone $this;
        $clone->processes[] = $process;
        return $clone;
    }

    public function backend(Backend $backend): self
    {
        $clone = clone $this;
        $clone->backends[] = $backend;
        return $clone;
    }

    public function frontend(Frontend $frontend): self
    {
        $clone = clone $this;
        $clone->frontends[] = $frontend;
        return $clone;
    }

    public function liveReload(int $port = 35729): self
    {
        $clone = clone $this;
        $clone->liveReloadPort = $port;
        return $clone;
    }

    public function when(bool $condition, Closure $fn): self
    {
        if (!$condition) {
            return $this;
        }

        return $fn($this);
    }

    public function run(): int
    {
        $cwd = getcwd() ?: '.';
        $allProcesses = $this->resolveProcesses($cwd);

        $output = new WritableResourceStream(STDOUT);
        $multiplexer = new Multiplexer($output);
        $managed = self::buildManagedProcesses($allProcesses);

        $reloadServer = null;

        if ($this->liveReloadPort !== null) {
            $reloadServer = ReloadServer::on($this->liveReloadPort);
            $reloadServer->start();
            $multiplexer->writeLine(
                "\033[2m[skopos] Live reload server on port {$this->liveReloadPort}\033[0m"
            );
        }

        self::printProcessTable($managed, $multiplexer);
        self::startAll($managed, $multiplexer);

        self::awaitReadiness($managed, $multiplexer, static function () use ($managed, $multiplexer, $output, $reloadServer): void {
            if ($reloadServer !== null) {
                self::wireReloadCallbacks($managed, $multiplexer, $reloadServer);
            }

            $watchers = self::startFileWatchers($managed, $multiplexer, $reloadServer);
            self::registerSignalHandler($managed, $multiplexer, $output, $watchers, $reloadServer);
            self::watchServerProcesses($managed, $multiplexer, $output, $reloadServer);
        });

        Loop::run();

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
    private static function startAll(array $managed, Multiplexer $multiplexer): void
    {
        foreach ($managed as $mp) {
            $mp->start($multiplexer);
        }
    }

    /** @param list<ManagedProcess> $managed */
    private static function awaitReadiness(array $managed, Multiplexer $multiplexer, Closure $onReady): void
    {
        $promises = array_map(
            static fn(ManagedProcess $mp): \React\Promise\PromiseInterface => $mp->readiness(),
            $managed,
        );

        all($promises)->then(
            static function () use ($multiplexer, $onReady): void {
                $multiplexer->writeLine('');
                $multiplexer->writeLine("\033[1;32m[skopos] All processes ready.\033[0m");
                $multiplexer->writeLine('');
                $onReady();
            },
            static function (\Throwable $e) use ($multiplexer): void {
                $multiplexer->writeLine(
                    "\033[31m[skopos] Readiness failed: {$e->getMessage()}\033[0m"
                );
                Loop::stop();
            },
        );
    }

    /** @param list<ManagedProcess> $managed */
    private static function wireReloadCallbacks(
        array $managed,
        Multiplexer $multiplexer,
        ReloadServer $reloadServer,
    ): void {
        foreach ($managed as $mp) {
            if ($mp->config->reloadProbe === null) {
                continue;
            }

            $name = $mp->config->name;

            $mp->onOutput(static function (string $line) use ($name, $multiplexer, $reloadServer): void {
                $multiplexer->writeLine(
                    "\033[2m[skopos] Reload triggered by {$name}\033[0m"
                );
                $reloadServer->reload();
            });
        }
    }

    /**
     * @param list<ManagedProcess> $managed
     * @return list<FileWatcher>
     */
    private static function startFileWatchers(
        array $managed,
        Multiplexer $multiplexer,
        ?ReloadServer $reloadServer = null,
    ): array {
        $watchers = [];

        foreach ($managed as $mp) {
            if ($mp->config->watchPaths === []) {
                continue;
            }

            $name = $mp->config->name;
            $paths = $mp->config->watchPaths;
            $extensions = $mp->config->watchExtensions;

            $multiplexer->writeLine(
                "\033[2m[skopos] Watching {$name}: " . implode(', ', $paths) . " [" . implode(', ', array_map(static fn(string $ext): string => ".{$ext}", $extensions)) . "]\033[0m"
            );

            $cwd = $mp->config->cwd ?? getcwd() ?: null;

            $watcher = new FileWatcher(
                $paths,
                $extensions,
                static function (array $changed) use ($mp, $name, $multiplexer, $reloadServer): void {
                    $short = array_map(static fn(string $path): string => basename($path), $changed);
                    $label = count($short) <= 3
                        ? implode(', ', $short)
                        : implode(', ', array_slice($short, 0, 3)) . ' +' . (count($short) - 3) . ' more';

                    $multiplexer->writeLine(
                        "\033[33m[skopos] Change detected ({$label}). Restarting {$name}...\033[0m"
                    );

                    $mp->restart($multiplexer)->then(
                        static function () use ($name, $multiplexer, $reloadServer): void {
                            $multiplexer->writeLine(
                                "\033[32m[skopos] {$name} restarted.\033[0m"
                            );
                            $reloadServer?->reload();
                        },
                    );
                },
                cwd: $cwd,
            );

            $watcher->start();
            $watchers[] = $watcher;
        }

        return $watchers;
    }

    /**
     * @param list<ManagedProcess> $managed
     * @param list<FileWatcher> $watchers
     */
    private static function registerSignalHandler(
        array $managed,
        Multiplexer $multiplexer,
        WritableResourceStream $output,
        array $watchers = [],
        ?ReloadServer $reloadServer = null,
    ): void {
        SignalHandler::register(static function () use ($managed, $multiplexer, $output, $watchers, $reloadServer): void {
            foreach ($watchers as $watcher) {
                $watcher->stop();
            }

            $reloadServer?->stop();
            self::shutdown($managed, $multiplexer, $output);
        });
    }

    /** @param list<ManagedProcess> $managed */
    private static function watchServerProcesses(
        array $managed,
        Multiplexer $multiplexer,
        WritableResourceStream $output,
        ?ReloadServer $reloadServer = null,
    ): void {
        foreach ($managed as $mp) {
            if (!$mp->config->isServer) {
                continue;
            }

            $name = $mp->config->name;

            $mp->onCrash(static function () use ($name, $managed, $multiplexer, $output, $reloadServer): void {
                $multiplexer->writeLine(
                    "\033[31m[skopos] Server process '{$name}' crashed. Shutting down.\033[0m"
                );
                $reloadServer?->stop();
                self::shutdown($managed, $multiplexer, $output);
            });
        }
    }

    /** @param list<ManagedProcess> $managed */
    private static function shutdown(
        array $managed,
        Multiplexer $multiplexer,
        WritableResourceStream $output,
    ): void {
        $multiplexer->writeLine('');
        $multiplexer->writeLine("\033[33m[skopos] Shutting down...\033[0m");

        $promises = array_map(
            static fn(ManagedProcess $mp): \React\Promise\PromiseInterface => $mp->stop()->then(
                static function () use ($mp, $multiplexer): void {
                    $multiplexer->writeLine(
                        "\033[2m[skopos] {$mp->config->name} stopped.\033[0m"
                    );
                },
            ),
            $managed,
        );

        all($promises)->then(static function () use ($multiplexer, $output): void {
            $multiplexer->writeLine("\033[33m[skopos] All processes stopped.\033[0m");
            $output->end();
            Loop::futureTick(static function (): void {
                Loop::stop();
            });
        });
    }

    private static function deriveNameFromCommand(string $command): string
    {
        $parts = explode(' ', trim($command));
        return basename($parts[0]);
    }

    /** @return list<Process> */
    private function resolveProcesses(string $cwd): array
    {
        $resolved = $this->processes;

        foreach ($this->backends as $backend) {
            $resolved[] = $backend->resolve();
        }

        foreach ($this->frontends as $frontend) {
            foreach ($frontend->resolve($cwd) as $process) {
                $resolved[] = $process;
            }
        }

        return $resolved;
    }
}
