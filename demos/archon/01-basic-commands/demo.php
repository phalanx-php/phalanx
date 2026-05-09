<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\Opt;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Archon\BasicCommands\DebugDeadlockCommand;
use Phalanx\Demos\Archon\BasicCommands\GreetCommand;
use Phalanx\Demos\Archon\BasicCommands\InfoCommand;
use Phalanx\Demos\Archon\BasicCommands\OutputBundle;
use Phalanx\Demos\Archon\BasicCommands\VersionCommand;
use Phalanx\Demos\Kit\DemoReport;

return DemoReport::demo(
    'Archon Basic Commands',
    static function (DemoReport $report, AppContext $context): void {
        $commands = CommandGroup::of([
            'greet' => [
                GreetCommand::class,
                new CommandConfig(
                    description: 'Greet someone by name.',
                    arguments:   [Arg::required('name', 'Person to greet.')],
                ),
            ],
            'version' => [
                VersionCommand::class,
                new CommandConfig(description: 'Print the demo version banner.'),
            ],
            'info' => [
                InfoCommand::class,
                new CommandConfig(
                    description: 'Print build info; --shout uppercases the body.',
                    options:     [Opt::flag('shout', 's', 'Uppercase the body.')],
                ),
            ],
            'debug:deadlock' => [
                DebugDeadlockCommand::class,
                new CommandConfig(
                    description: 'Snapshot every parked coroutine (operator escape hatch).',
                    options:     [Opt::flag('json', '', 'Emit JSON instead of formatted text.')],
                ),
            ],
        ]);

        $runCase = static function (string $label, array $argv, string $expected) use ($commands, $report): void {
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                throw new \RuntimeException('php://temp unavailable');
            }
            $capture = new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));

            $app = Archon::starting(['argv' => array_merge(['demo'], $argv)])
                ->providers(new OutputBundle($capture))
                ->commands($commands)
                ->build();

            $code = $app->run();
            $app->shutdown();

            rewind($stream);
            $captured = (string) stream_get_contents($stream);
            fclose($stream);

            $ok = $code === 0 && str_contains($captured, $expected);
            $detail = sprintf("expected substring: %s\nexit code: %d\noutput:\n%s", $expected, $code, $captured);
            $report->record($label, $ok, $ok ? '' : $detail);
        };

        $runCase('greet Ada',             ['greet', 'Ada'],             'Hello, Ada.');
        $runCase('version',               ['version'],                  'archon-demo 0.1');
        $runCase('info --shout',          ['info', '--shout'],          'PHALANX ARCHON');
        $runCase('debug:deadlock',        ['debug:deadlock'],           '[DEADLOCK REPORT]');
        $runCase('debug:deadlock --json', ['debug:deadlock', '--json'], '"coroutineCount":');
    },
);
