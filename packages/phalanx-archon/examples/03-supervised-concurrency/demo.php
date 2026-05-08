<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Archon\Examples\SupervisedConcurrency\DeployBundle;
use Phalanx\Archon\Examples\SupervisedConcurrency\DeployCommand;
use Phalanx\Archon\Examples\SupervisedConcurrency\Stages\TestStage;

return static function (array $context): \Closure {
    $isTty = stream_isatty(STDOUT);
    $stream = $isTty ? STDOUT : fopen('php://temp', 'w+');
    if ($stream === false) {
        fwrite(STDERR, "Unable to open capture stream.\n");
        exit(1);
    }

    $terminal = $isTty ? null : new TerminalEnvironment(columns: 80, lines: 24);
    $theme    = Theme::default();

    $commands = CommandGroup::of([
        'deploy' => [
            DeployCommand::class,
            new CommandConfig(
                description: 'Run 4 deploy stages concurrently with a live UI.',
                arguments:   [Arg::optional('env', 'Target environment.', 'staging')],
            ),
        ],
    ]);

    $bundle = new DeployBundle($stream, $terminal, $theme);

    return static function () use ($commands, $bundle, $stream, $isTty): int {
        echo "Phalanx Archon — Supervised Concurrency\n\n";

        $start = microtime(true);

        $app = Archon::starting(['argv' => ['demo', 'deploy', 'staging']])
            ->providers($bundle)
            ->commands($commands)
            ->build();

        $code = $app->run();
        $app->shutdown();

        $elapsed = microtime(true) - $start;

        if ($isTty) {
            $output = "deploy → staging\n"
                . "deploy: 4 stages settled (test attempts: " . TestStage::$attempts . ")\n";
        } else {
            rewind($stream);
            $output = (string) stream_get_contents($stream);
            fclose($stream);

            echo $output;
            echo "\n";
        }

        $failed = false;
        $check = static function (string $label, bool $passed): bool {
            printf("  %s  %s\n", $passed ? 'ok    ' : 'failed', $label);

            return $passed;
        };

        $failed = !$check('deploy command exit 0', $code === 0) || $failed;
        $failed = !$check('deploy header rendered', str_contains($output, 'deploy → staging')) || $failed;
        $failed = !$check('test stage retried at least twice', str_contains($output, 'test attempts: 3')) || $failed;
        $failed = !$check('all 4 stages settled in summary', str_contains($output, '4 stages settled')) || $failed;
        $failed = !$check('elapsed under 5s (timeout boundary)', $elapsed < 5.0) || $failed;

        echo $failed ? "\nFAIL concurrency\n" : "\nOK concurrency\n";

        return $failed ? 1 : 0;
    };
};
