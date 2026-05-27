<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command\Config;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;
use Phalanx\Themis\ConfigCatalog;
use Phalanx\Themis\EnvExampleGenerator;

/**
 * Generates or updates a .env.example file from registered config classes.
 *
 * When --dry-run is passed the generated content is printed to stdout instead
 * of written to disk. Existing .env.example values are preserved for keys that
 * the catalog does not produce, so manually added annotations survive updates.
 *
 * Options:
 *   --dry-run   Print to stdout instead of writing .env.example.
 *   --output    Path to write to (default: .env.example in the working directory).
 */
final class EnvExampleCommand implements Scopeable
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
