<?php

declare(strict_types=1);

namespace Phalanx\Console\Command\Config;

use Phalanx\Config\ConfigCatalog;
use Phalanx\Config\EnvExampleGenerator;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Command\Opt;
use Phalanx\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

final class EnvExampleCommand implements Scopeable, DescribesCommand
{
    public function __invoke(CommandContext $ctx): int
    {
        $catalog = $ctx->service(ConfigCatalog::class);
        $output = $ctx->service(StreamOutput::class);

        $dryRun = $ctx->options->flag('dry-run');
        $outputPath = (string) ($ctx->options->get('output') ?? '.env.example');

        $knownValues = self::parseExistingFile($outputPath);
        $generator = new EnvExampleGenerator();
        $content = $generator->generate($catalog->roots, $knownValues);

        if ($dryRun) {
            foreach (explode("\n", rtrim($content)) as $line) {
                $output->persist($line);
            }

            return 0;
        }

        $result = file_put_contents($outputPath, $content);

        if ($result === false) {
            $output->persist('Error: could not write to ' . $outputPath);

            return 1;
        }

        $output->persist('Written: ' . $outputPath);

        return 0;
    }

    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Generate or update a .env.example file from registered config classes.',
            options: [
                Opt::flag(name: 'dry-run', desc: 'Print to stdout instead of writing to disk.'),
                Opt::value(name: 'output', desc: 'Output path (default: .env.example).', default: '.env.example'),
            ],
        );
    }

    /**
     * Parse an existing .env-style file into a key => value map so the
     * generator can preserve values the catalog does not define.
     *
     * @return array<string, string>
     */
    private static function parseExistingFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            return [];
        }

        $values = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');

            if ($pos === false) {
                continue;
            }

            $key = substr($line, 0, $pos);
            $value = substr($line, $pos + 1);
            $values[$key] = $value;
        }

        return $values;
    }
}
