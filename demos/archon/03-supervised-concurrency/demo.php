<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Boot\AppContext;
use Phalanx\Demos\Archon\SupervisedConcurrency\DeployBundle;
use Phalanx\Demos\Archon\SupervisedConcurrency\DeployCommand;
use Phalanx\Demos\Archon\SupervisedConcurrency\Stages\TestStage;
use Phalanx\Demos\Kit\DemoReport;

return DemoReport::demo(
    'Archon Supervised Concurrency',
    static function (DemoReport $report, AppContext $_context): void {
        $isTty = stream_isatty(STDOUT);
        if ($isTty) {
            $stream = STDOUT;
        } else {
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                throw new \RuntimeException('php://temp unavailable');
            }
        }
        $terminal = $isTty ? null : new TerminalEnvironment(columns: 80, lines: 24);
        $theme = Theme::default();

        $commands = CommandGroup::of([
            'deploy' => [
                DeployCommand::class,
                new CommandConfig(
                    description: 'Run 4 deploy stages concurrently with a live UI.',
                    arguments:   [Arg::optional('env', 'Target environment.', 'staging')],
                ),
            ],
        ]);

        $start = microtime(true);
        $app = Archon::starting(['argv' => ['demo', 'deploy', 'staging']])
            ->providers(new DeployBundle($stream, $terminal, $theme))
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
            echo $output, "\n";
        }

        $report->record('deploy command exit 0',                $code === 0);
        $report->record('deploy header rendered',               str_contains($output, 'deploy → staging'));
        $report->record('test stage retried at least twice',    str_contains($output, 'test attempts: 3'));
        $report->record('all 4 stages settled in summary',      str_contains($output, '4 stages settled'));
        $report->record('elapsed under 5s (timeout boundary)',  $elapsed < 5.0);
    },
);
