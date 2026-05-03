<?php

declare(strict_types=1);

namespace Phalanx\Archon;

use Closure;
use InvalidArgumentException;
use Phalanx\AppHost;
use Phalanx\Application;
use Phalanx\ApplicationBuilder;
use Phalanx\Middleware\ServiceTransformationMiddleware;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Service\ServiceBundle;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use Phalanx\Worker\WorkerDispatch;

final class ArchonBuilder
{
    private ApplicationBuilder $app;

    /** @var list<CommandGroup|string|list<string>|array<string, mixed>> */
    private array $commandSources = [];

    /** @var array<string, InlineCommand> */
    private array $inlineCommands = [];

    private ?ConsoleConfig $consoleConfig = null;

    /** @param array<string, mixed> $context */
    public function __construct(private readonly array $context = [])
    {
        $this->app = Application::starting($context);
    }

    /**
     * @param CommandGroup|string|list<string>|array<string, mixed> $source
     */
    private static function resolveCommands(AppHost $app, CommandGroup|string|array $source): CommandGroup
    {
        if ($source instanceof CommandGroup) {
            return $source;
        }

        if (is_string($source)) {
            return self::loadCommandPath($app, $source);
        }

        if (array_is_list($source)) {
            if (!array_all($source, static fn(mixed $value): bool => is_string($value))) {
                throw new InvalidArgumentException('Command path lists must contain only strings.');
            }

            $group = CommandGroup::of([]);
            foreach ($source as $path) {
                $group = $group->merge(self::loadCommandPath($app, $path));
            }

            return $group;
        }

        /** @var array<string, class-string<Scopeable|Executable>|array{class-string<Scopeable|Executable>, CommandConfig}|CommandGroup> $source */
        return CommandGroup::of($source);
    }

    private static function loadCommandPath(AppHost $app, string $path): CommandGroup
    {
        $scope = $app->createScope();

        try {
            if (is_dir($path)) {
                return CommandLoader::loadDirectory($path, $scope);
            }

            return CommandLoader::load($path, $scope);
        } finally {
            $scope->dispose();
        }
    }

    public function providers(ServiceBundle ...$providers): self
    {
        $this->app->providers(...$providers);
        return $this;
    }

    public function serviceMiddleware(ServiceTransformationMiddleware ...$middlewares): self
    {
        $this->app->serviceMiddleware(...$middlewares);
        return $this;
    }

    public function taskMiddleware(TaskMiddleware ...$middlewares): self
    {
        $this->app->taskMiddleware(...$middlewares);
        return $this;
    }

    public function withTrace(Trace $trace): self
    {
        $this->app->withTrace($trace);
        return $this;
    }

    public function withWorkerDispatch(WorkerDispatch $dispatch): self
    {
        $this->app->withWorkerDispatch($dispatch);
        return $this;
    }

    public function withRuntimePolicy(RuntimePolicy $policy): self
    {
        $this->app->withRuntimePolicy($policy);
        return $this;
    }

    public function withRuntimeHooksStrict(bool $strict): self
    {
        $this->app->withRuntimeHooksStrict($strict);
        return $this;
    }

    public function withLedger(LedgerStorage $ledger): self
    {
        $this->app->withLedger($ledger);
        return $this;
    }

    /**
     * @param CommandGroup|string|list<string>|array<string, mixed> $commands
     */
    public function commands(CommandGroup|string|array $commands): self
    {
        $this->commandSources[] = $commands;
        return $this;
    }

    public function command(
        string $name,
        Closure|Scopeable|Executable $handler,
        ?CommandConfig $config = null,
    ): self {
        $this->inlineCommands[$name] = InlineCommand::named($name, $handler, $config);
        return $this;
    }

    public function default(string $command): self
    {
        $this->consoleConfig = $this->resolveConsoleConfig()->withDefaultCommand($command);
        return $this;
    }

    public function withConsoleConfig(ConsoleConfig $config): self
    {
        $this->consoleConfig = $config;
        return $this;
    }

    public function build(): ArchonApplication
    {
        $host = $this->app->compile();
        $commands = CommandGroup::of([]);

        foreach ($this->commandSources as $source) {
            $commands = $commands->merge(self::resolveCommands($host, $source));
        }

        return new ArchonApplication(
            host: $host,
            commands: $commands,
            consoleConfig: $this->resolveConsoleConfig(),
            inlineCommands: $this->inlineCommands,
        );
    }

    /** @param list<string>|null $argv */
    public function run(?array $argv = null): int
    {
        return $this->build()->run($argv);
    }

    private function resolveConsoleConfig(): ConsoleConfig
    {
        return $this->consoleConfig ?? ConsoleConfig::fromContext($this->context);
    }
}
