<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Console\Application\Console;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Console\Input\RawInput;
use Phalanx\Console\Console\Output\StreamOutput;
use Phalanx\Console\Console\Output\TerminalEnvironment;
use Phalanx\Console\Console\Style\Theme;
use Phalanx\Boot\AppContext;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Demos\Console\InteractiveInput\InputBundle;
use Phalanx\Demos\Console\InteractiveInput\RegisterCommand;
use Phalanx\Demos\Console\InteractiveInput\SetConfigCommand;
use Phalanx\Demos\Console\InteractiveInput\ShowConfigCommand;
use Phalanx\Demos\Kit\DemoReport;

return DemoReport::demo(
    'Console Interactive Input (non-TTY default fallback)',
    static function (DemoReport $report, AppContext $_context): void {
        $commands = CommandGroup::of([
            'register' => RegisterCommand::class,
            'config'   => CommandGroup::of([
                'show' => ShowConfigCommand::class,
                'set'  => SetConfigCommand::class,
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
            // /dev/null avoids the Swoole reactor's kqueue path on real
            // terminal fds and produces deterministic output.
            $nullStream = fopen('/dev/null', 'r');
            if ($nullStream === false) {
                throw new \RuntimeException('/dev/null unavailable');
            }
            $reader = new RawInput(new ConsoleInput($nullStream));

            $app = Console::starting(['argv' => array_merge(['demo'], $argv)])
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
        $runCase('config show',                   ['config', 'show'],                 'endpoint = https://console.local');
        $runCase('config set retries 5',          ['config', 'set', 'retries', '5'],  'set retries = 5');
    },
);
