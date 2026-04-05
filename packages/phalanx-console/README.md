<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/console

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
composer require phalanx/console
```

Requires PHP 8.4+ and `phalanx/core`.

## Quick Start

```php
<?php

use Phalanx\Console\Arg;
use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\CommandScope;
use Phalanx\Console\ConsoleRunner;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final readonly class GreetCommand implements Scopeable
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
    'greet' => new Command(
        fn: new GreetCommand(),
        desc: 'Greet someone by name',
        args: [Arg::optional('name', 'Name to greet', default: 'world')],
    ),
]);

$runner = ConsoleRunner::withCommands($app, $commands);
exit($runner->run($argv));
```

```
$ php app.php greet Jonathan
Hello, Jonathan!
```

## Defining Commands

A command handler is an invokable class implementing `Scopeable` or `Executable`. At dispatch time it receives a `CommandScope`—which extends `ExecutionScope`—giving it access to parsed arguments, options, services, and concurrency primitives.

`Scopeable` handlers receive `Scope`:

```php
<?php

use Phalanx\Console\CommandScope;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final readonly class MigrateCommand implements Scopeable
{
    public function __invoke(Scope $scope): mixed
    {
        /** @var CommandScope $scope */
        $step = $scope->options->get('step', 'all');
        $dry = $scope->options->flag('dry-run');

        $pending = $scope->service(Migrations::class)->pending();

        foreach ($pending as $migration) {
            if ($dry) {
                echo "[dry-run] Would apply: {$migration->name}\n";
                continue;
            }
            $scope->execute($migration);
            echo "Applied: {$migration->name}\n";
        }

        return 0;
    }
}
```

`Executable` handlers receive `ExecutionScope` directly—use this when you don't need the broader `Scope` interface:

```php
<?php

use Phalanx\Console\CommandScope;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final readonly class PingCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        /** @var CommandScope $scope */
        echo "pong\n";

        return 0;
    }
}
```

Both work interchangeably—`Command` accepts `Closure|Scopeable|Executable`.

## Command Configuration

Pass `desc`, `args`, `opts`, and `validators` directly to the `Command` constructor. Use the `Arg` and `Opt` factories to build argument and option definitions:

```php
<?php

use Phalanx\Console\Arg;
use Phalanx\Console\Command;
use Phalanx\Console\Opt;

$migrate = new Command(
    fn: new MigrateCommand(),
    desc: 'Run pending database migrations',
    args: [Arg::required('database', 'Connection name')],
    opts: [
        Opt::value('step', 's', 'Number of migrations to run', default: 'all'),
        Opt::flag('dry-run', 'd', 'Preview without applying'),
        Opt::flag('force', 'f', 'Skip confirmation prompts'),
    ],
);
```

## Arguments and Options

### Arg factory

```php
<?php

use Phalanx\Console\Arg;

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

use Phalanx\Console\Opt;

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

`CommandGroup` collects commands into a registry:

```php
<?php

use Phalanx\Console\CommandGroup;

// From an array
$commands = CommandGroup::of([
    'deploy'  => $deploy,
    'migrate' => $migrate,
    'seed'    => $seed,
]);

// Fluent builder
$commands = CommandGroup::create()
    ->command('deploy', new DeployCommand(), 'Deploy the application')
    ->command('migrate', new MigrateCommand(), 'Run database migrations')
    ->command('seed', new SeedCommand(), 'Seed the database');

// Merge groups together
$all = $coreCommands->merge($pluginCommands);
```

## Nested Command Groups

Groups can contain other groups for hierarchical CLI structures:

```php
<?php

use Phalanx\Console\Arg;
use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\Opt;

$commands = CommandGroup::of([
    'serve' => new Command(
        fn: new ServeCommand(),
        desc: 'Start the HTTP server',
        opts: [Opt::value('port', 'p', 'Port number', default: '8080')],
    ),
    'db' => CommandGroup::of([
        'migrate' => new Command(
            fn: new MigrateCommand(),
            desc: 'Run database migrations',
            opts: [Opt::flag('fresh', 'f', 'Drop all tables first')],
        ),
        'seed' => new Command(
            fn: new SeedCommand(),
            desc: 'Seed the database',
            args: [Arg::optional('class', 'Seeder class')],
        ),
        'reset' => new Command(fn: new ResetCommand(), desc: 'Reset the database'),
    ], description: 'Database operations'),
    'make' => CommandGroup::of([
        'task'    => new Command(fn: new MakeTaskCommand(), desc: 'Create a new task class'),
        'command' => new Command(fn: new MakeCommandCommand(), desc: 'Create a new command class'),
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
use Phalanx\Console\Arg;
use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\Opt;

return CommandGroup::of([
    'migrate' => new Command(
        fn: new MigrateCommand(),
        desc: 'Run database migrations',
        opts: [Opt::flag('fresh', 'f', 'Drop all tables first')],
    ),
    'seed' => new Command(
        fn: new SeedCommand(),
        desc: 'Seed the database',
    ),
]);
```

Load a single file or scan an entire directory:

```php
<?php

use Phalanx\Console\CommandLoader;

// Single file
$commands = CommandLoader::load(__DIR__ . '/commands/db.php');

// All .php files in a directory (non-recursive, merged)
$commands = CommandLoader::loadDirectory(__DIR__ . '/commands');
```

`ConsoleRunner` accepts directory paths directly and handles loading:

```php
<?php

use Phalanx\Console\ConsoleRunner;

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

use Phalanx\Console\CommandConfig;
use Phalanx\Console\CommandInput;
use Phalanx\Console\CommandValidator;
use Phalanx\Console\InvalidInputException;

final readonly class RequireFreshOnProduction implements CommandValidator
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

Attach validators via the `validators` parameter:

```php
<?php

use Phalanx\Console\Arg;
use Phalanx\Console\Command;
use Phalanx\Console\Opt;

$deploy = new Command(
    fn: new DeployCommand(),
    desc: 'Deploy the application',
    args: [Arg::required('environment', 'Target environment')],
    opts: [Opt::flag('force', 'f', 'Skip confirmation prompts')],
    validators: [new RequireFreshOnProduction()],
);
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

use Phalanx\Console\CommandScope;
use Phalanx\ExecutionScope;
use Phalanx\Task;
use Phalanx\Task\Executable;

final readonly class HealthCheckCommand implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        /** @var CommandScope $scope */
        $services = ['api', 'database', 'cache', 'queue'];

        $results = $scope->concurrent(
            array_map(
                static fn(string $name) => Task::of(
                    static fn($s) => $s->service(HealthChecker::class)->check($name)
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

use Phalanx\Console\Command;

$healthCheck = new Command(
    fn: new HealthCheckCommand(),
    desc: 'Check health of all services',
);
```

All concurrency runs on the ReactPHP event loop under the hood. The command handler reads like synchronous code.
