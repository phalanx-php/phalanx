<?php

declare(strict_types=1);

namespace Phalanx\DoryBin\Command;

use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Command\Opt;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Boot\AppContext;
use Phalanx\DoryBin\BuildOptions;
use Phalanx\DoryBin\BuildProfile;
use Phalanx\DoryBin\DoryBin;
use Phalanx\Task\Scopeable;

final class BuildBinaryCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Build a static Dory binary',
            options: [
                Opt::value('profile', 'p', 'Build profile', default: 'full'),
                Opt::value('output', 'o', 'Output binary path'),
                Opt::flag('clean', 'c', 'Clean build directory first'),
                Opt::flag('verbose', 'v', 'Show build subprocess output'),
                Opt::flag('dry-run', 'd', 'Show planned stages without executing'),
                Opt::value('spc-path', '', 'Path to spc binary'),
            ],
        );
    }
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);

        $profileName = (string) $ctx->options->get('profile', 'full');
        $outputPath = $ctx->options->get('output');
        $clean = $ctx->options->flag('clean');
        $dryRun = $ctx->options->flag('dry-run');

        $profile = BuildProfile::tryFrom($profileName);

        if ($profile === null) {
            $output->persist("Unknown profile: {$profileName}");
            return 1;
        }

        $appContext = $ctx->service(AppContext::class);
        $env = $appContext->get('env', []);

        if (!is_array($env)) {
            $env = [];
        }

        $stringEnv = array_filter($env, static fn(mixed $v): bool => is_string($v));

        $options = new BuildOptions(
            profile: $profile,
            outputPath: is_string($outputPath) ? $outputPath : null,
            clean: $clean,
            dryRun: $dryRun,
            env: $stringEnv,
        );

        if ($dryRun) {
            $output->persist("Dry run: would execute build for profile '{$profileName}'");
            DoryBin::build($ctx, $options);
            return 0;
        }

        $output->persist("Building Dory binary (profile: {$profileName})");
        $output->persist('');

        $outcome = DoryBin::build($ctx, $options);

        if (!$outcome->success) {
            $failed = $outcome->failedStage;
            if ($failed !== '') {
                $output->persist('');
                $output->persist("Build failed at stage '{$failed}'");
            }
            return 1;
        }

        $output->persist('');

        $binaryPath = $outcome->binaryPath;
        if ($binaryPath !== null && is_file($binaryPath)) {
            $size = sprintf('%.1f MB', filesize($binaryPath) / 1_048_576);
            $output->persist("Binary built: {$binaryPath} ({$size})");
        }

        return 0;
    }
}
