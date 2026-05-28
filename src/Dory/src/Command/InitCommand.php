<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command;

use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

final class InitCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Initialize a new Dory project',
            arguments: [
                Arg::optional('directory', 'Target directory', '.'),
            ],
        );
    }
    private const string SAMPLE_SCRIPT = <<<'PHP'
        <?php

        declare(strict_types=1);

        dory()->println('Greetings from Olympus.');

        $result = dory()->attempt(static fn(): string => 'The phalanx holds.')
            ->timeout(5.0)
            ->run();

        dory()->dump($result);

        return 0;
        PHP;

    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);

        $directory = (string) $ctx->args->get('directory', '.');
        $absolute = realpath($directory) ?: $directory;

        if (!is_dir($absolute) && !mkdir($absolute, 0755, recursive: true)) {
            fwrite(STDERR, "Could not create directory: {$directory}\n");
            return 1;
        }

        $scriptPath = rtrim($absolute, '/') . '/hello.php';

        if (file_exists($scriptPath)) {
            $output->persist("File already exists: {$scriptPath}");
            return 0;
        }

        file_put_contents($scriptPath, self::SAMPLE_SCRIPT . "\n");

        $output->persist("Created: {$scriptPath}");
        $output->persist('');
        $output->persist('Run it with:');
        $output->persist("  dory run {$scriptPath}");

        return 0;
    }
}
