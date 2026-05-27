<?php

declare(strict_types=1);

use Phalanx\Dory\Build\BuildConfig;
use Phalanx\Dory\Build\BuildProfile;
use Phalanx\Dory\Build\BuildProfileRegistry;
use Phalanx\Dory\Build\Pipeline\BuildPipeline;
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
use Phalanx\Dory\Dory;
use Phalanx\Dory\ScriptContext;

/**
 * @var ScriptContext $dory
 * build-dory.php -- Dory builds itself.
 *
 * Usage:
 *   dory run scripts/build-dory.php
 *   dory run scripts/build-dory.php -- --profile=mini --output=./dory-mini
 */
 
/** @var ScriptContext $dory */
$profileName = $argv[1] ?? 'full';
$outputPath = null;

foreach ($argv as $i => $arg) {
    if (str_starts_with($arg, '--profile=')) {
        $profileName = substr($arg, strlen('--profile='));
    }
    if (str_starts_with($arg, '--output=')) {
        $outputPath = substr($arg, strlen('--output='));
    }
}

$profile = BuildProfile::tryFrom($profileName);

if ($profile === null) {
    $dory->println("Unknown profile: {$profileName}");
    $dory->println('Available: mini, ops, brain, full');
    return 1;
}

$dory->println("Building Dory binary (profile: {$profileName})");
$dory->println('');

$config = new BuildConfig();
$registry = new BuildProfileRegistry(BuildProfileRegistry::defaultProfileDir());
$definition = $registry->get($profile);
$context = SpcBuildContext::forProfile($definition, $config, $outputPath);

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

$pipeline = new BuildPipeline();

foreach ($stages as $stage) {
    $pipeline->add($stage);
}

$results = $pipeline->execute($dory, $context);

$dory->println('');
$dory->println('Build results:');

$failed = false;

foreach ($results as $result) {
    $icon = match (true) {
        $result->skipped => '-',
        $result->success => '+',
        default => '!',
    };

    $time = $result->skipped ? '' : sprintf(' (%.1fs)', $result->durationMs / 1000);

    $dory->println("  [{$icon}] {$result->stageName}{$time}: {$result->summary}");

    if (!$result->success && !$result->skipped) {
        $failed = true;
    }
}

if ($failed) {
    $dory->println('');
    $dory->println('Build failed.');
    return 1;
}

$dory->println('');

if (is_file($context->outputPath)) {
    $size = sprintf('%.1f MB', filesize($context->outputPath) / 1_048_576);
    $dory->println("Binary: {$context->outputPath} ({$size})");
}

$dory->println('Done.');

return 0;
