<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Archon

> Part of the [Phalanx](https://github.com/phalanx-php/phalanx-aegis) async PHP framework.

Build CLI applications with the same scope-driven concurrency that powers Phalanx HTTP servers. Define commands as invokable classes, group them, load them from directories, and let the framework handle argument parsing, validation, and help generation.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Defining Commands](#defining-commands)
- [Command Configuration](#command-configuration)
- [Arguments and Options](#arguments-and-options)
- [Grouping Commands](#grouping-commands)
- [Nested Command Groups](#nested-command-groups)
- [Loading Commands from Files](#loading-commands-from-files)
- [Validation](#validation)
- [The CommandScope](#the-commandscope)
- [Running Concurrent Work](#running-concurrent-work)

## Installation

```bash
composer require phalanx/archon
```

> [!NOTE]
> Requires PHP 8.4 or later.

## Quick Start

```php
<?php

use Phalanx\Application;
use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\CommandScope;
use Phalanx\Archon\ConsoleRunner;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final class GreetCommand implements Scopeable
{
    public function __invoke(Scope $scope): mixed
    {
        /** @var CommandScope $scope */
        $name = $scope->args->get('name', 'world');
        echo "Hello, {$name}!\n";

        return 0;
    }
}

$app = Application::starting()->compile();

$commands = CommandGroup::of([
    'greet' => [GreetCommand::class, new CommandConfig(
        description: 'Greet someone by name',
        arguments: [Arg::optional('name', 'Name to greet', default: 'world')],
    )],
]);

$runner = ConsoleRunner::withCommands($app, $commands);
exit($runner->run($argv));
```

```
$ php app.php greet Jonathan
Hello, Jonathan!
```

## Defining Commands

A command handler is an invokable class implementing `Scopeable` or `Executable`. At dispatch time the `HandlerResolver` constructs the handler with its constructor dependencies injected from the service container, then passes a `CommandScope` to `__invoke`.

`Scopeable` handlers receive `Scope` and use it for service resolution:

```php
<?php

use Phalanx\Archon\CommandScope;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final class RunMigrations implements Scopeable
{
    public function __construct(
        private readonly MigrationRunner $migrations,
    ) {}

    public function __invoke(Scope $scope): mixed
    {
        /** @var CommandScope $scope */
        $fresh = $scope->options->flag('fresh');
        $dry = $scope->options->flag('dry-run');

        $pending = $this->migrations->pending();

        foreach ($pending as $migration) {
            if ($dry) {
                echo "[dry-run] Would apply: {$migration->name}\n";
                continue;
            }
            $this->migrations->run($migration);
            echo "Applied: {$migration->name}\n";
        }

        return 0;
    }
}
```

`Executable` handlers receive `ExecutionScope` directly -- use this when the command needs concurrency primitives like `concurrent()` or `map()`:

```php
<?php

use Phalanx\Archon\CommandScope;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class PingCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        /** @var CommandScope $scope */
        echo "pong\n";

        return 0;
    }
}
```

Constructors take repositories, clients, and services. Pure helpers that don't need sharing stay as direct calls inside `__invoke`.

## Command Configuration

Register commands in a `CommandGroup` using class-strings. When a command needs description, arguments, options, or validators, pair the class-string with a `CommandConfig`:

```php
<?php

use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\Opt;

$commands = CommandGroup::of([
    'migrate' => [RunMigrations::class, new CommandConfig(
        description: 'Run pending database migrations',
        arguments: [Arg::required('database', 'Connection name')],
        options: [
            Opt::value('step', 's', 'Number of migrations to run', default: 'all'),
            Opt::flag('dry-run', 'd', 'Preview without applying'),
            Opt::flag('force', 'f', 'Skip confirmation prompts'),
        ],
    )],
]);
```

## Arguments and Options

### Arg factory

```php
<?php

use Phalanx\Archon\Arg;

Arg::required('name', 'Description');
Arg::optional('name', 'Description', default: 'fallback');
```

Positional arguments are matched by declaration order, accessed by name:

```php
<?php

$scope->args->get('name');            // value or null
$scope->args->get('name', 'default'); // value or default
$scope->args->required('name');       // value or throws InvalidInputException
$scope->args->has('name');            // bool
$scope->args->all();                  // array<string, mixed>
```

### Opt factory

```php
<?php

use Phalanx\Archon\Opt;

Opt::flag('verbose', 'v', 'Enable verbose output');           // --verbose / -v (boolean)
Opt::value('format', 'f', 'Output format', default: 'json');  // --format=json / -f json (requires value)
```

Options are parsed from `--long`, `-s`, `--long=value`, and `-s value` forms. `--` stops option parsing—everything after is positional:

```php
<?php

$scope->options->get('format');            // value or null
$scope->options->get('format', 'json');    // value or default
$scope->options->flag('verbose');          // bool
$scope->options->has('format');            // bool
$scope->options->all();                    // array<string, mixed>
```

## Grouping Commands

`CommandGroup` collects commands into a registry. Commands without extra config are registered as bare class-strings; commands that need metadata use the `[class-string, CommandConfig]` tuple form:

```php
<?php

use Phalanx\Archon\CommandGroup;

$commands = CommandGroup::of([
    'deploy'  => DeployApplication::class,
    'migrate' => RunMigrations::class,
    'seed'    => SeedDatabase::class,
]);

// Merge groups together
$all = $coreCommands->merge($pluginCommands);
```

## Nested Command Groups

Groups can contain other groups for hierarchical CLI structures:

```php
<?php

use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\Opt;

$commands = CommandGroup::of([
    'serve' => [ServeHttp::class, new CommandConfig(
        description: 'Start the HTTP server',
        options: [Opt::value('port', 'p', 'Port number', default: '8080')],
    )],
    'db' => CommandGroup::of([
        'migrate' => [RunMigrations::class, new CommandConfig(
            description: 'Run database migrations',
            options: [Opt::flag('fresh', 'f', 'Drop all tables first')],
        )],
        'seed' => [SeedDatabase::class, new CommandConfig(
            description: 'Seed the database',
            arguments: [Arg::optional('class', 'Seeder class')],
        )],
        'reset' => [ResetDatabase::class, new CommandConfig(
            description: 'Reset the database',
        )],
    ], description: 'Database operations'),
    'make' => CommandGroup::of([
        'task'    => [MakeTask::class, new CommandConfig(description: 'Create a new task class')],
        'command' => [MakeCommand::class, new CommandConfig(description: 'Create a new command class')],
    ], description: 'Code generation'),
]);
```

Invoke nested commands with space-separated names:

```bash
php app.php serve --port=8080
php app.php db migrate --fresh
php app.php make task FetchUser
php app.php db --help
```

Typing a group name without a subcommand shows its help:

```
Database operations

Usage:
  db <command> [options]

Commands:
  migrate  Run database migrations
  seed     Seed the database
  reset    Reset the database

Run 'db <command> --help' for details.
```

Top-level help separates commands from groups:

```
Usage:
  app <command> [options]

Commands:
  serve   Start the HTTP server

Groups:
  db      Database operations
  make    Code generation
```

Groups nest arbitrarily deep. Both flat commands and nested groups work in the same `CommandGroup`.

## Loading Commands from Files

`CommandLoader` loads command definitions from PHP files that return a `CommandGroup`:

```php
<?php

// commands/db.php
use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\Opt;

return CommandGroup::of([
    'migrate' => [RunMigrations::class, new CommandConfig(
        description: 'Run database migrations',
        options: [Opt::flag('fresh', 'f', 'Drop all tables first')],
    )],
    'seed' => [SeedDatabase::class, new CommandConfig(
        description: 'Seed the database',
    )],
]);
```

Load a single file or scan an entire directory:

```php
<?php

use Phalanx\Archon\CommandLoader;

// Single file
$commands = CommandLoader::load(__DIR__ . '/commands/db.php');

// All .php files in a directory (non-recursive, merged)
$commands = CommandLoader::loadDirectory(__DIR__ . '/commands');
```

`ConsoleRunner` accepts directory paths directly and handles loading:

```php
<?php

use Phalanx\Archon\ConsoleRunner;

// From a CommandGroup
$runner = ConsoleRunner::withCommands($app, $commands);

// From a directory path
$runner = ConsoleRunner::withCommands($app, __DIR__ . '/commands');

// From multiple directories
$runner = ConsoleRunner::withCommands($app, [
    __DIR__ . '/commands',
    __DIR__ . '/plugins/commands',
]);

exit($runner->run($argv));
```

## Validation

Implement `CommandValidator` to add custom input validation. Validators run after parsing, before the handler executes:

```php
<?php

use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandInput;
use Phalanx\Archon\CommandValidator;
use Phalanx\Archon\InvalidInputException;

final readonly class RequireForceOnProduction implements CommandValidator
{
    public function validate(CommandInput $input, CommandConfig $config): void
    {
        $env = $input->args->get('environment');
        $force = $input->options->flag('force');

        if ($env === 'production' && !$force) {
            throw new InvalidInputException(
                'Production deployments require --force',
                $config,
            );
        }
    }
}
```

Attach validators via `CommandConfig`:

```php
<?php

use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\Opt;

$commands = CommandGroup::of([
    'deploy' => [DeployApplication::class, new CommandConfig(
        description: 'Deploy the application',
        arguments: [Arg::required('environment', 'Target environment')],
        options: [Opt::flag('force', 'f', 'Skip confirmation prompts')],
        validators: [new RequireForceOnProduction()],
    )],
]);
```

When an `InvalidInputException` carries a `CommandConfig`, the runner automatically prints contextual help alongside the error message.

## The CommandScope

`CommandScope` extends `ExecutionScope` with typed property hooks for the matched command:

```php
<?php

interface CommandScope extends ExecutionScope
{
    public string $commandName { get; }
    public CommandArgs $args { get; }
    public CommandOptions $options { get; }
    public CommandConfig $config { get; }
}
```

Inside a handler, access everything through the scope:

```php
<?php

public function __invoke(Scope $scope): mixed
{
    /** @var CommandScope $scope */

    $scope->commandName;                     // 'migrate'
    $scope->args->get('database');           // positional arg value
    $scope->options->flag('force');          // boolean flag
    $scope->options->get('step', 'all');     // option with default
    $scope->config;                          // CommandConfig instance

    $scope->service(Migrations::class);      // resolve a service
    $scope->execute($task);                  // execute a task in scope
    $scope->concurrent([...]);               // run tasks concurrently

    return 0;
}
```

## Running Concurrent Work

Because `CommandScope` extends `ExecutionScope`, every command has access to Phalanx's concurrency primitives. A CLI tool that checks multiple services concurrently:

```php
<?php

use Phalanx\Archon\CommandScope;
use Phalanx\ExecutionScope;
use Phalanx\Task;
use Phalanx\Task\Executable;

final class HealthCheckCommand implements Executable
{
    public function __construct(
        private readonly HealthChecker $checker,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        /** @var CommandScope $scope */
        $services = ['api', 'database', 'cache', 'queue'];

        $results = $scope->concurrent(
            array_map(
                fn(string $name) => Task::of(
                    static fn() => $this->checker->check($name)
                ),
                $services,
            ),
        );

        foreach ($services as $i => $name) {
            $status = $results[$i] ? 'OK' : 'FAIL';
            echo "  {$name}: {$status}\n";
        }

        return array_any($results, static fn($r) => !$r) ? 1 : 0;
    }
}
```

```php
<?php

use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;

$commands = CommandGroup::of([
    'health' => [HealthCheckCommand::class, new CommandConfig(
        description: 'Check health of all services',
    )],
]);
```

All concurrency runs on the ReactPHP event loop under the hood. The command handler reads like synchronous code.
