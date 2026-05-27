<?php

declare(strict_types=1);

namespace Phalanx\DoryBin;

final class BuildManifest
{
    /**
     * @param array<string, bool>   $openSwooleFeatures
     * @param list<string>          $extensions
     * @param list<string>          $phalanxPackages
     * @param array<string, bool>   $verificationResults
     */
    public function __construct(
        private(set) string $doryVersion,

        private(set) string $profileName,

        private(set) string $timestamp,

        private(set) string $os,

        private(set) string $arch,

        private(set) string $phpVersion,

        private(set) string $openSwooleVersion,

        private(set) array $openSwooleFeatures,

        private(set) array $extensions,

        private(set) array $phalanxPackages,

        private(set) string $binaryPath,

        private(set) int $binarySizeBytes,

        private(set) string $binarySha256,

        private(set) array $verificationResults,
    ) {
    }
}
