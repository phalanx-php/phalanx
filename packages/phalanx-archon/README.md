<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Archon

> Part of the [Phalanx](https://github.com/phalanx-php/phalanx-aegis) async PHP framework.

Build CLI applications on the same Aegis-managed runtime used by Phalanx services. Commands are invokable classes that receive `CommandScope`, run supervised child work, and close through managed `archon.command` resources.

---

## Application

```php
<?php

declare(strict_types=1);

use Phalanx\Archon\Archon;
use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;
use Phalanx\Archon\CommandScope;
use Phalanx\Archon\ConsoleConfig;
use Phalanx\Archon\ConsoleSignalPolicy;
use Phalanx\Archon\Opt;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Archon\Style\ConsoleServiceBundle;
use Phalanx\Task\Scopeable;

final class DeployCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $image = (string) $scope->args->required('image');

        $scope->service(StreamOutput::class)->persist("Deploying {$image}");

        return 0;
    }
}

$commands = CommandGroup::of([
    'deploy' => [
        DeployCommand::class,
        new CommandConfig(
            description: 'Deploy an image.',
            arguments: [
                Arg::required('image', 'Image tag to deploy.'),
            ],
            options: [
                Opt::flag('dry-run', 'd', 'Preview without deploying.'),
            ],
        ),
    ],
]);

return static function (array $context) use ($commands) {
    return Archon::starting($context)
        ->providers(new ConsoleServiceBundle())
        ->withConsoleConfig(new ConsoleConfig(
            defaultCommand: 'help',
            signalPolicy: ConsoleSignalPolicy::default(),
        ))
        ->commands($commands)
        ->build();
};
```

For direct scripts, pass CLI input at the boundary:

```php
<?php

exit(Archon::starting(['argv' => $argv])
    ->providers(new ConsoleServiceBundle())
    ->commands($commands)
    ->run());
```

## Current Boundaries

- `Archon::starting($context)` is the application entry point.
- `CommandGroup::of(...)` registers class-string command handlers and nested groups.
- `ConsoleConfig` owns argv, default command, output streams, terminal dimensions, and signal policy.
- `CommandScope` exposes parsed args/options, command identity, managed resource id, services, cancellation, and supervised task execution.

Interactive prompts, React-backed widgets, and timer-driven TUI surfaces are still being replaced for the 0.2 OpenSwoole runtime. Do not build new code against those APIs yet.
