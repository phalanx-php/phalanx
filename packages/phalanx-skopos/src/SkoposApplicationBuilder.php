<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

use Phalanx\Application;
use Phalanx\ApplicationBuilder;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;

/**
 * Facade builder for Skopos dev-server applications.
 *
 * Bootstrap files should enter through `Skopos::starting($context)`. The
 * builder accumulates managed-process configuration, backends, frontends,
 * and the LiveReload port, then on run() compiles a Phalanx Application
 * and dispatches the long-running DevServer task on its root scope.
 */
final class SkoposApplicationBuilder
{
    private ApplicationBuilder $app;

    /** @var list<Process> */
    private array $processes = [];

    /** @var list<Backend> */
    private array $backends = [];

    /** @var list<Frontend> */
    private array $frontends = [];

    private ?int $liveReloadPort = null;

    private bool $quiet = false;

    public function __construct(AppContext $context = new AppContext())
    {
        $this->app = Application::starting($context->values);
    }

    public function providers(ServiceBundle ...$providers): self
    {
        $this->app->providers(...$providers);
        return $this;
    }

    public function process(Process ...$processes): self
    {
        foreach ($processes as $process) {
            $this->processes[] = $process;
        }
        return $this;
    }

    public function backend(Backend ...$backends): self
    {
        foreach ($backends as $backend) {
            $this->backends[] = $backend;
        }
        return $this;
    }

    public function frontend(Frontend ...$frontends): self
    {
        foreach ($frontends as $frontend) {
            $this->frontends[] = $frontend;
        }
        return $this;
    }

    /**
     * Convenience for adding a long-running server process. Equivalent to
     * passing a Process built with named()->command()->asServer()->ready().
     */
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

        $this->processes[] = $process;
        return $this;
    }

    public function liveReload(int $port = 35729): self
    {
        $this->liveReloadPort = $port;
        return $this;
    }

    public function quiet(bool $quiet = true): self
    {
        $this->quiet = $quiet;
        return $this;
    }

    public function run(): int
    {
        $devServer = new DevServer(
            processes: $this->resolveProcesses(),
            liveReloadPort: $this->liveReloadPort,
            quiet: $this->quiet,
        );

        return $this->app->compile()->run($devServer);
    }

    /** @return list<Process> */
    private function resolveProcesses(): array
    {
        $cwd = getcwd();
        if ($cwd === false) {
            $cwd = '.';
        }

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

    private static function deriveNameFromCommand(string $command): string
    {
        $parts = explode(' ', trim($command));
        return basename($parts[0]);
    }
}
