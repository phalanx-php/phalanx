<?php

declare(strict_types=1);

namespace Phalanx\Console\Application;

use Closure;
use InvalidArgumentException;
use Phalanx\AppHost;
use Phalanx\Application as RuntimeApplication;
use Phalanx\ApplicationBuilder;
use Phalanx\Boot\AppContext;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Command\CommandLoader;
use Phalanx\Console\Command\Config\ConfigCommandGroup;
use Phalanx\Console\Command\InlineCommand;
use Phalanx\Console\Input\ConsoleInputServiceBundle;
use Phalanx\Console\Style\Bundle;
use Phalanx\Middleware\ServiceTransformationMiddleware;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Service\ServiceBundle;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use Phalanx\Worker\WorkerDispatch;

/**
 * Module entry builder for Console console applications.
 *
 * Bootstrap files should enter through `Console::starting($context)`, not
 * through the root Runtime ApplicationBuilder plus a manually assembled runner.
 */
final class Builder
{
    private ApplicationBuilder $app;

    /** @var list<CommandGroup|string|list<string>|array<string, mixed>> */
    private array $commandSources = [];

    /** @var array<string, InlineCommand> */
    private array $inlineCommands = [];

    /** @var list<\Phalanx\Console\ErrorRenderer> */
    private array $errorRenderers = [];

    private ?Config $config = null;

    public function __construct(private readonly AppContext $context = new AppContext())
    {
        $this->app = RuntimeApplication::starting($context->values);
        $this->app->providers(new ConsoleInputServiceBundle());
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

    public function withErrorRenderers(\Phalanx\Console\ErrorRenderer ...$renderers): self
    {
        $this->errorRenderers = array_values([...$this->errorRenderers, ...$renderers]);

        return $this;
    }

    public function withErrorHandler(\Phalanx\Exception\ErrorHandler ...$handlers): self
    {
        $this->app->withErrorHandler(...$handlers);

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
        $this->config = $this->resolveConfig()->withDefaultCommand($command);

        return $this;
    }

    public function withConfig(Config $config): self
    {
        $this->config = $config;

        return $this;
    }

    public function build(): Application
    {
        $this->app->providers(new Bundle());
        $host = $this->app->compile();

        // Built-in commands are the base layer; user-defined commands take precedence.
        $commands = ConfigCommandGroup::commands();

        foreach ($this->commandSources as $source) {
            $commands = $commands->merge(self::resolveCommands($host, $source));
        }

        return new Application(
            host: $host,
            commands: $commands,
            config: $this->resolveConfig(),
            inlineCommands: $this->inlineCommands,
            errorRenderers: $this->errorRenderers,
        );
    }

    /** @param list<string>|null $argv */
    public function run(?array $argv = null): int
    {
        return $this->build()->run($argv);
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
                return CommandLoader::loadDirectory($scope, $path);
            }

            return CommandLoader::load($scope, $path);
        } finally {
            $scope->dispose();
        }
    }

    private function resolveConfig(): Config
    {
        return $this->config ?? Config::fromContext($this->context);
    }
}
