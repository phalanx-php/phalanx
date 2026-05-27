<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build;

use Phalanx\Dory\Build\Spc\SpcBuildContext;

final class BuildManifestWriter
{
    public const string DORY_VERSION = '0.6.0';

    public static function fromContext(SpcBuildContext $context, string $binaryPath): BuildManifest
    {
        return new BuildManifest(
            doryVersion: self::DORY_VERSION,
            profileName: $context->profile->profile->value,
            timestamp: date('c'),
            os: $context->os,
            arch: $context->arch,
            phpVersion: $context->profile->phpVersion,
            openSwooleVersion: $context->profile->openSwooleVersion,
            openSwooleFeatures: $context->profile->openSwooleFeatures,
            extensions: $context->profile->allExtensions(),
            phalanxPackages: $context->profile->phalanxPackages,
            binaryPath: $binaryPath,
            binarySizeBytes: is_file($binaryPath) ? (int) filesize($binaryPath) : 0,
            binarySha256: is_file($binaryPath) ? (string) hash_file('sha256', $binaryPath) : '',
            verificationResults: [],
        );
    }

    public static function write(BuildManifest $manifest, string $outputPath): void
    {
        $data = [
            'version' => $manifest->doryVersion,
            'profile' => $manifest->profileName,
            'timestamp' => $manifest->timestamp,
            'platform' => [
                'os' => $manifest->os,
                'arch' => $manifest->arch,
            ],
            'php' => ['version' => $manifest->phpVersion],
            'openswoole' => [
                'version' => $manifest->openSwooleVersion,
                'features' => $manifest->openSwooleFeatures,
            ],
            'extensions' => $manifest->extensions,
            'phalanx' => ['packages' => $manifest->phalanxPackages],
            'binary' => [
                'path' => $manifest->binaryPath,
                'size_bytes' => $manifest->binarySizeBytes,
                'sha256' => $manifest->binarySha256,
            ],
            'verification' => $manifest->verificationResults,
        ];

        $written = file_put_contents(
            $outputPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        if ($written === false) {
            throw new \RuntimeException('Failed to write build manifest to ' . $outputPath);
        }
    }
}
