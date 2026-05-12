<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Console\Input\RawInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Boot\AppContext;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Demos\Archon\InteractiveInput\InputBundle;
use Phalanx\Demos\Archon\InteractiveInput\RegisterCommand;
use Phalanx\Demos\Archon\InteractiveInput\SetConfigCommand;
use Phalanx\Demos\Archon\InteractiveInput\ShowConfigCommand;
use Phalanx\Demos\Kit\DemoReport;

return DemoReport::demo(
    'Archon Interactive Input (non-TTY default fallback)',
    static function (DemoReport $report, AppContext $_context): void {
        $commands = CommandGroup::of([
            'register' => [
                RegisterCommand::class,
                new CommandConfig(description: 'Register a demo account through interactive prompts.'),
            ],
            'config' => CommandGroup::of([
                'show' => [
                    ShowConfigCommand::class,
                    new CommandConfig(description: 'Display the current demo config.'),
                ],
                'set' => [
                    SetConfigCommand::class,
                    new CommandConfig(
                        description: 'Set a config value.',
                        arguments:   [
                            Arg::required('key',   'Config key.'),
                            Arg::required('value', 'New value.'),
                        ],
                    ),
                ],
            ], description: 'Demo configuration commands.'),
        ]);

        $runCase = static function (string $label, array $argv, string $expected) use ($commands, $report): void {
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                throw new \RuntimeException('php://temp unavailable');
            }
            $capture = new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));
            $theme = Theme::default();

            // Bind KeyReader to a /dev/null-backed ConsoleInput so prompts
            // short-circuit to their configured defaults. Routing through
            // /dev/null avoids the OpenSwoole reactor's kqueue path on real
            // terminal fds and produces deterministic output.
            $nullStream = fopen('/dev/null', 'r');
            if ($nullStream === false) {
                throw new \RuntimeException('/dev/null unavailable');
            }
            $reader = new RawInput(new ConsoleInput($nullStream));

            $app = Archon::starting(['argv' => array_merge(['demo'], $argv)])
                ->providers(new InputBundle($capture, $theme, $reader))
                ->commands($commands)
                ->build();

            $code = $app->run();
            $app->shutdown();

            rewind($stream);
            $captured = (string) stream_get_contents($stream);
            fclose($stream);
            fclose($nullStream);

            $ok = $code === 0 && str_contains($captured, $expected);
            $detail = sprintf("expected substring: %s\nexit code: %d\noutput:\n%s", $expected, $code, $captured);
            $report->record($label, $ok, $ok ? '' : $detail);
        };

        $runCase('register (non-TTY → defaults)', ['register'],                       'Registered: demo@phalanx.local on free (terms=yes)');
        $runCase('config show',                   ['config', 'show'],                 'endpoint = https://archon.local');
        $runCase('config set retries 5',          ['config', 'set', 'retries', '5'],  'set retries = 5');
    },
);
