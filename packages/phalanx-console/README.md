<p align="center">
  <img src="https://raw.githubusercontent.com/havy-tech/phalanx/main/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/console

Build CLI applications with the same scope-driven concurrency that powers Phalanx HTTP servers. Define commands as closures, group them, load them from directories, and let the framework handle argument parsing, validation, and help generation.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Defining Commands](#defining-commands)
- [Command Configuration](#command-configuration)
- [Grouping Commands](#grouping-commands)
- [Loading Commands from Files](#loading-commands-from-files)
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

use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\CommandScope;
use Phalanx\Console\ConsoleRunner;

$app = Application::starting()->compile();

$commands = CommandGroup::of([
    'greet' => new Command(
        fn: static function (CommandScope $scope): int {
            $name = $scope->args->get('name', 'world');
            echo "Hello, {$name}!\n";
            return 0;
        },
        config: fn($c) => $c
            ->withDescription('Greet someone by name')
            ->withArgument('name', 'Name to greet', required: false),
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

A `Command` wraps a closure and a `CommandConfig`. The closure receives a `CommandScope` at dispatch time, giving it access to parsed arguments, options, and the full Phalanx execution scope.

```php
<?php

use Phalanx\Console\Command;
use Phalanx\Console\CommandConfig;
use Phalanx\Console\CommandScope;

$migrate = new Command(
    fn: static function (CommandScope $scope): int {
        $step = $scope->options->get('step', 'all');
        $dry = $scope->options->has('dry-run');

        // Full ExecutionScope access -- run concurrent tasks, use services
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
    },
    config: fn($c) => $c
        ->withDescription('Run pending database migrations')
        ->withOption('step', 's', 'Number of migrations to run', requiresValue: true)
        ->withOption('dry-run', 'd', 'Preview without applying'),
);
```

The `config` parameter accepts either a `CommandConfig` instance or a closure that receives a fresh `CommandConfig` and returns the configured version.

## Command Configuration

`CommandConfig` builds up arguments and options through an immutable fluent API:

```php
<?php

use Phalanx\Console\CommandConfig;

$config = (new CommandConfig())
    ->withDescription('Deploy the application')
    ->withArgument('environment', 'Target environment', required: true)
    ->withArgument('tag', 'Git tag to deploy', required: false, default: 'latest')
    ->withOption('force', 'f', 'Skip confirmation prompts')
    ->withOption('concurrency', 'c', 'Max concurrent tasks', requiresValue: true, default: '4');
```

Each call returns a new `CommandConfig` -- the original stays untouched.

## Grouping Commands

`CommandGroup` collects commands into a named registry. Build one from an array, or use the fluent builder:

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
    ->command('deploy', $deploy, 'Deploy the application')
    ->command('migrate', $migrate, 'Run database migrations')
    ->command('seed', $seed, 'Seed the database');

// Merge groups together
$all = $coreCommands->merge($pluginCommands);
```

## Loading Commands from Files

`CommandLoader` scans a directory and loads every `.php` file that returns a `CommandGroup`:

```php
<?php

use Phalanx\Console\CommandLoader;

// Load all command files from a directory
$commands = CommandLoader::loadDirectory(__DIR__ . '/commands');
```

Each file returns a `CommandGroup`:

```php
<?php

// commands/deploy.php
use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\CommandScope;

return CommandGroup::of([
    'deploy' => new Command(
        fn: static function (CommandScope $scope): int {
            // ...
            return 0;
        },
        config: fn($c) => $c->withDescription('Deploy the application'),
    ),
]);
```

`ConsoleRunner` accepts directory paths directly and handles the loading:

```php
<?php

// Load from one or more directories
$runner = ConsoleRunner::withCommands($app, __DIR__ . '/commands');
$runner = ConsoleRunner::withCommands($app, [__DIR__ . '/commands', __DIR__ . '/plugins']);
```

## The CommandScope

`CommandScope` extends `ExecutionScope` with typed property hooks for the matched command: `$commandName`, `$args`, `$options`, and `$config`. Access parsed arguments by position or name, and options by name or shorthand.

## Running Concurrent Work

Because `CommandScope` extends `ExecutionScope`, every command has access to Phalanx's concurrency primitives. A CLI tool that fetches data from multiple sources concurrently:

```php
<?php

use Phalanx\Console\Command;
use Phalanx\Console\CommandScope;
use Phalanx\Task;

$healthCheck = new Command(
    fn: static function (CommandScope $scope): int {
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
    },
    config: fn($c) => $c->withDescription('Check health of all services'),
);
```

All concurrency runs on the ReactPHP event loop under the hood. The command closure reads like synchronous code.
