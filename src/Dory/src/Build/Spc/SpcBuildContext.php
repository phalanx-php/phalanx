<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build\Spc;

use Phalanx\Dory\Build\BuildConfig;
use Phalanx\Dory\Build\BuildProfileDefinition;

final class SpcBuildContext
{
    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        private(set) string $spcBinaryPath,
        private(set) string $buildRoot,
        private(set) string $registryPath,
        private(set) string $sourcePath,
        private(set) string $outputPath,
        private(set) array $environment,
        private(set) BuildProfileDefinition $profile,
        private(set) string $os,
        private(set) string $arch,
        private(set) string $workspaceRoot,
    ) {
    }

    /**
     * @param array<string, string> $env Caller-supplied environment (e.g. from symfony/runtime $context).
     *                                    Keys PATH, HOME, and LANG are used when present.
     */
    public static function forProfile(
        BuildProfileDefinition $profile,
        BuildConfig $config,
        ?string $outputPath = null,
        array $env = [],
    ): self {
        $home = $env['HOME'] ?? '';
        $spcBinaryPath = $config->spcPath !== '' ? $config->spcPath : (self::resolveSpcBinary($config, $home) ?? 'spc');
        $buildRoot = $config->buildRoot;
        $registryPath = $buildRoot . '/registry';
        $sourcePath = $buildRoot . '/source';
        $resolvedOutput = $outputPath ?? $buildRoot . '/bin/dory';

        $environment = [
            'SPC_REGISTRIES' => $registryPath,
            'PATH' => $env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $home,
            'LANG' => $env['LANG'] ?? 'en_US.UTF-8',
        ];

        $os = php_uname('s');
        $arch = php_uname('m');

        // Monorepo root: src/Dory/src/Build/Spc/ is 5 levels deep from root
        $workspaceRoot = dirname(__DIR__, 5);

        return new self(
            spcBinaryPath: $spcBinaryPath,
            buildRoot: $buildRoot,
            registryPath: $registryPath,
            sourcePath: $sourcePath,
            outputPath: $resolvedOutput,
            environment: $environment,
            profile: $profile,
            os: $os,
            arch: $arch,
            workspaceRoot: $workspaceRoot,
        );
    }

    private static function resolveSpcBinary(BuildConfig $config, string $home): ?string
    {
        if ($config->spcPath !== '' && is_executable($config->spcPath)) {
            return $config->spcPath;
        }

        $candidates = [
            $home . '/spc/bin/spc',
            $home . '/.local/bin/spc',
            '/usr/local/bin/spc',
            '/usr/bin/spc',
        ];

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
