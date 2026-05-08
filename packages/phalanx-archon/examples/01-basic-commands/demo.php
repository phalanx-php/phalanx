<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\Opt;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Examples\BasicCommands\DebugDeadlockCommand;
use Phalanx\Archon\Examples\BasicCommands\GreetCommand;
use Phalanx\Archon\Examples\BasicCommands\InfoCommand;
use Phalanx\Archon\Examples\BasicCommands\OutputBundle;
use Phalanx\Archon\Examples\BasicCommands\VersionCommand;

return static function (array $context): \Closure {
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

    return static function () use ($commands): int {
        $indent = static function (string $text): string {
            $lines = explode("\n", $text);
            foreach ($lines as &$line) {
                $line = '      ' . $line;
            }
            return implode("\n", $lines);
        };

        $runCase = static function (
            string $label,
            array $argv,
            string $expectedSubstring,
            CommandGroup $commands,
        ) use ($indent): bool {
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                fwrite(STDERR, "Unable to open capture stream.\n");
                return false;
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

            $passed = $code === 0 && str_contains($captured, $expectedSubstring);

            printf("  %-26s %s\n", $label, $passed ? 'ok' : 'failed');
            if (!$passed) {
                printf("    expected substring: %s\n", $expectedSubstring);
                printf("    actual exit code:   %d\n", $code);
                printf("    actual output:\n%s\n", $indent($captured));
            }

            return $passed;
        };

        echo "Phalanx Archon — Basic Commands\n\n";

        $failed = !$runCase('greet Ada',             ['greet', 'Ada'],             'Hello, Ada.',       $commands) || false;
        $failed = !$runCase('version',               ['version'],                  'archon-demo 0.1',   $commands) || $failed;
        $failed = !$runCase('info --shout',          ['info', '--shout'],          'PHALANX ARCHON',    $commands) || $failed;
        $failed = !$runCase('debug:deadlock',        ['debug:deadlock'],           '[DEADLOCK REPORT]', $commands) || $failed;
        $failed = !$runCase('debug:deadlock --json', ['debug:deadlock', '--json'], '"coroutineCount":', $commands) || $failed;

        echo $failed ? "FAIL basic\n" : "OK basic\n";

        return $failed ? 1 : 0;
    };
};
