<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command\Build;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Dory\Build\BuildConfig;
use Phalanx\Dory\Build\BuildProfile;
use Phalanx\Dory\Build\BuildProfileRegistry;
use Phalanx\Dory\Build\Filesystem;
use Phalanx\Dory\Build\Pipeline\BuildPipeline;
use Phalanx\Dory\Build\Pipeline\BuildProgress;
use Phalanx\Dory\Build\Spc\SpcBuildContext;
use Phalanx\Dory\Build\Stage\BuildLibraries;
use Phalanx\Dory\Build\Stage\BuildPhp;
use Phalanx\Dory\Build\Stage\DownloadSources;
use Phalanx\Dory\Build\Stage\EmbedPhalanx;
use Phalanx\Dory\Build\Stage\PatchOpenSwoole;
use Phalanx\Dory\Build\Stage\PreflightCheck;
use Phalanx\Dory\Build\Stage\SetupRegistry;
use Phalanx\Dory\Build\Stage\StashSources;
use Phalanx\Dory\Build\Stage\VerifyBinary;
use Phalanx\Dory\Build\Stage\WriteManifest;
use Phalanx\Task\Scopeable;

final class BuildBinaryCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $output = $ctx->service(StreamOutput::class);
        $config = $ctx->service(BuildConfig::class);

        $profileName = (string) $ctx->options->get('profile', $config->defaultProfile);
        $outputPath = $ctx->options->get('output');
        $clean = $ctx->options->flag('clean');
        $dryRun = $ctx->options->flag('dry-run');

        $profile = BuildProfile::tryFrom($profileName);

        if ($profile === null) {
            $output->persist("Unknown profile: {$profileName}");
            return 1;
        }

        $registry = new BuildProfileRegistry(BuildProfileRegistry::defaultProfileDir());
        $definition = $registry->get($profile);

        $buildRoot = $config->buildRoot;

        if ($clean && is_dir($buildRoot)) {
            Filesystem::removeDir($buildRoot);
        }

        if (!is_dir($buildRoot) && !mkdir($buildRoot, 0755, true) && !is_dir($buildRoot)) {
            $output->persist("Failed to create build root: {$buildRoot}");
            return 1;
        }

        $context = SpcBuildContext::forProfile($definition, $config, is_string($outputPath) ? $outputPath : null, $_ENV + $_SERVER);

        $stages = [
            new PreflightCheck(),
            new SetupRegistry(),
            new StashSources(),
            new DownloadSources(),
            new BuildLibraries(),
            new PatchOpenSwoole(),
            new BuildPhp(),
            new EmbedPhalanx(),
            new VerifyBinary(),
            new WriteManifest(),
        ];

        if ($dryRun) {
            $output->persist("Dry run: would execute " . count($stages) . " stages for profile '{$profileName}':");
            foreach ($stages as $stage) {
                $skip = $stage->canSkip($context) ? ' (would skip)' : '';
                $output->persist("  - {$stage->description}{$skip}");
            }
            return 0;
        }

        $output->persist("Building Dory binary (profile: {$profileName})");
        $output->persist('');

        $progress = new BuildProgress($output);
        $progress->registerStages(...$stages);

        $pipeline = new BuildPipeline();
        foreach ($stages as $stage) {
            $pipeline->add($stage);
        }

        $results = $pipeline->execute($ctx, $context, $progress);

        $failed = array_find($results, static fn($r): bool => !$r->success && !$r->skipped);

        if ($failed !== null) {
            $output->persist('');
            $output->persist("Build failed at stage '{$failed->stageName}': {$failed->summary}");
            return 1;
        }

        $output->persist('');
        $size = is_file($context->outputPath)
            ? sprintf('%.1f MB', filesize($context->outputPath) / 1_048_576)
            : 'unknown';
        $output->persist("Binary built: {$context->outputPath} ({$size})");

        return 0;
    }
}
