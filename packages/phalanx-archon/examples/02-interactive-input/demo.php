<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\ArchonDemo\Interactive\RegisterCommand;
use Acme\ArchonDemo\Interactive\SetConfigCommand;
use Acme\ArchonDemo\Interactive\ShowConfigCommand;
use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Console\Input\KeyReader;
use Phalanx\Archon\Console\Input\RawInput;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Style;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

$failed = false;

echo "Phalanx Archon — Interactive Input (non-TTY default fallback)\n\n";

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

$failed = !runCase('register (non-TTY → defaults)', ['register'],                'Registered: demo@phalanx.local on free (terms=yes)', $commands) || $failed;
$failed = !runCase('config show',                   ['config', 'show'],          'endpoint = https://archon.local',                    $commands) || $failed;
$failed = !runCase('config set retries 5',          ['config', 'set', 'retries', '5'], 'set retries = 5',                                $commands) || $failed;

echo $failed ? "FAIL interactive\n" : "OK interactive\n";
exit($failed ? 1 : 0);

/** @param list<string> $argv */
function runCase(string $label, array $argv, string $expectedSubstring, CommandGroup $commands): bool
{
    $stream = fopen('php://temp', 'w+');
    if ($stream === false) {
        fwrite(STDERR, "Unable to open capture stream.\n");
        return false;
    }

    $capture = new StreamOutput($stream, new TerminalEnvironment(columns: 80, lines: 24));
    $theme   = Theme::default();

    // Pull ConsoleInput from the scope (Archon auto-registers it bound to STDIN,
    // which is non-interactive under composer's pipe so prompts return defaults).
    $bundle = new class($capture, $theme) implements ServiceBundle {
        public function __construct(
            private StreamOutput $output,
            private Theme $theme,
        ) {
        }

        public function services(Services $services, array $context): void
        {
            $services->singleton(StreamOutput::class)->factory(fn() => $this->output);
            $services->singleton(Theme::class)->factory(fn() => $this->theme);
            $services->scoped(KeyReader::class)
                ->needs(ConsoleInput::class)
                ->factory(static fn(ConsoleInput $input): KeyReader => new RawInput($input));
        }
    };

    $app = Archon::starting(['argv' => array_merge(['demo'], $argv)])
        ->providers($bundle)
        ->commands($commands)
        ->build();

    $code = $app->run();
    $app->shutdown();

    rewind($stream);
    $captured = (string) stream_get_contents($stream);
    fclose($stream);

    $passed = $code === 0 && str_contains($captured, $expectedSubstring);

    printf("  %-36s %s\n", $label, $passed ? 'ok' : 'failed');
    if (!$passed) {
        printf("    expected substring: %s\n", $expectedSubstring);
        printf("    actual exit code:   %d\n", $code);
        printf("    actual output:\n%s\n", indentLines($captured));
    }

    return $passed;
}

function indentLines(string $text): string
{
    $lines = explode("\n", $text);
    foreach ($lines as &$line) {
        $line = '      ' . $line;
    }
    return implode("\n", $lines);
}
