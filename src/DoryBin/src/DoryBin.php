<?php

declare(strict_types=1);

namespace Phalanx\DoryBin;

use Phalanx\DoryBin\Pipeline\BuildPipeline;
use Phalanx\DoryBin\Spc\SpcBuildContext;
use Phalanx\DoryBin\Stage\BuildLibraries;
use Phalanx\DoryBin\Stage\BuildPhp;
use Phalanx\DoryBin\Stage\DownloadSources;
use Phalanx\DoryBin\Stage\EmbedPhalanx;
use Phalanx\DoryBin\Stage\PatchSwoole;
use Phalanx\DoryBin\Stage\PreflightCheck;
use Phalanx\DoryBin\Stage\SetupRegistry;
use Phalanx\DoryBin\Stage\StashSources;
use Phalanx\DoryBin\Stage\VerifyBinary;
use Phalanx\DoryBin\Stage\WriteManifest;
use Phalanx\DoryBin\Verify\BinarySizeCheck;
use Phalanx\DoryBin\Verify\ExtensionCheck;
use Phalanx\DoryBin\Verify\FiberContextCheck;
use Phalanx\DoryBin\Verify\SmokeTestCheck;
use Phalanx\DoryBin\Verify\SymbolConflictCheck;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;

final class DoryBin
{
    public static function services(): DoryBinServiceBundle
    {
        return new DoryBinServiceBundle();
    }

    public static function build(TaskScope&TaskExecutor $scope, BuildOptions $options): BuildOutcome
    {
        $config = $scope->service(BuildConfig::class);
        $registry = $scope->service(BuildProfileRegistry::class);

        $definition = $registry->get($options->profile);
        $context = SpcBuildContext::forProfile($definition, $config, $options->outputPath, $options->env);

        if ($options->clean && is_dir($config->buildRoot)) {
            Filesystem::removeDir($config->buildRoot);
        }

        $stages = [
            new PreflightCheck(),
            new SetupRegistry(),
            new StashSources(),
            new DownloadSources(),
            new BuildLibraries(),
            new PatchSwoole(),
            new BuildPhp(),
            new EmbedPhalanx(),
            new VerifyBinary(),
            new WriteManifest(),
        ];

        if ($options->dryRun) {
            return BuildOutcome::dryRun();
        }

        $start = hrtime(true);

        $pipeline = new BuildPipeline();
        foreach ($stages as $stage) {
            $pipeline->add($stage);
        }

        $results = $pipeline->execute($scope, $context);
        $totalMs = (hrtime(true) - $start) / 1_000_000;

        $success = array_all($results, static fn($r): bool => $r->success || $r->skipped);
        $binaryPath = $success && is_file($context->outputPath) ? $context->outputPath : null;

        return new BuildOutcome(
            success: $success,
            stages: $results,
            binaryPath: $binaryPath,
            manifest: null,
            totalMs: $totalMs,
        );
    }

    public static function verify(TaskScope&TaskExecutor $scope, VerifyOptions $options): VerifyOutcome
    {
        $registry = $scope->service(BuildProfileRegistry::class);
        $definition = $options->profile !== null
            ? $registry->get($options->profile)
            : $registry->getByName('full');

        $checks = [
            new ExtensionCheck(),
            new FiberContextCheck(),
            new SmokeTestCheck(),
            new SymbolConflictCheck(),
            new BinarySizeCheck(),
        ];

        $start = hrtime(true);
        $results = [];

        foreach ($checks as $check) {
            $results[] = $check->check($scope, $options->binaryPath, $definition);
        }

        $totalMs = (hrtime(true) - $start) / 1_000_000;
        $passed = array_all($results, static fn($r): bool => $r->passed);

        return new VerifyOutcome(
            passed: $passed,
            results: $results,
            binaryPath: $options->binaryPath,
            totalMs: $totalMs,
        );
    }
}
