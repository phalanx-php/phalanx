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

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Application\ConsoleConfig;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalPolicy;
use Phalanx\Archon\Command\Opt;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\ConsoleServiceBundle;
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

Interactive prompts (`Console\Input\BasePrompt` and friends), composite widgets (`Console\Widget\Form`, `Accordion`, `ConcurrentTaskList`), and `Scan\ScanProgress` run on the Aegis-managed runtime: each `prompt()`, `submit()`, and `run()` accepts `Suspendable&Disposable` plus a `KeyReader`, suspending through `$scope->call(...)` under typed `WaitReason::input()` waits and tying any periodic redraw to a scope-owned `Subscription`.

## Prompts

`ConsoleServiceBundle` registers `KeyReader` (scoped) alongside `Theme` (singleton) and `StreamOutput` (singleton). A command resolves the three from its scope and drives any prompt synchronously:

```php
<?php

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Input\KeyReader;
use Phalanx\Archon\Console\Input\TextInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Task\Scopeable;

final class AskCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $name = (new TextInput(
            theme:   $scope->service(Theme::class),
            label:   'What is your name?',
            default: 'operator',
        ))->prompt(
            $scope,
            $scope->service(StreamOutput::class),
            $scope->service(KeyReader::class),
        );

        return 0;
    }
}
```

Non-TTY runs (CI, redirected stdin) short-circuit to the prompt's configured default — no key reads, no rendering.
