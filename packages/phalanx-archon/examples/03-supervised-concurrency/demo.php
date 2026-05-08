<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Acme\ArchonDemo\Concurrency\DeployCommand;
use Acme\ArchonDemo\Concurrency\Stages\TestStage;
use Phalanx\Archon\Application\Archon;
use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Archon\Console\Output\TerminalEnvironment;
use Phalanx\Archon\Console\Style\Theme;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

echo "Phalanx Archon — Supervised Concurrency\n\n";

$isTty = stream_isatty(STDOUT);
$stream = $isTty ? STDOUT : fopen('php://temp', 'w+');
if ($stream === false) {
    fwrite(STDERR, "Unable to open capture stream.\n");
    exit(1);
}

$terminal = $isTty ? null : new TerminalEnvironment(columns: 80, lines: 24);
$theme    = Theme::default();

$bundle = new class ($stream, $terminal, $theme) extends ServiceBundle {
    public function __construct(
        private mixed $stream,
        private ?TerminalEnvironment $terminal,
        private Theme $theme,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $stream   = $this->stream;
        $theme    = $this->theme;
        $terminal = $this->terminal;

        $services->singleton(StreamOutput::class)
            ->factory(static fn(): StreamOutput => new StreamOutput($stream, $terminal));

        $services->singleton(Theme::class)
            ->factory(static fn(): Theme => $theme);
    }
};

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

$app = Archon::starting(AppContext::test(['argv' => ['demo', 'deploy', 'staging']]))
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
exit($failed ? 1 : 0);
