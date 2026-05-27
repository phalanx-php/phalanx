<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build;

final class BuildProfileDefinition
{
    /**
     * @param array<string, string> $iniSettings
     * @param list<string>          $requiredExtensions
     * @param list<string>          $optionalExtensions
     * @param array<string, bool>   $openSwooleFeatures
     * @param list<string>          $phalanxPackages
     * @param list<string>          $spcRegistries
     */
    public function __construct(
        private(set) BuildProfile $profile,
        private(set) string $description,
        private(set) string $phpVersion,
        private(set) array $iniSettings,
        private(set) string $iniPath,
        private(set) string $iniScanDir,
        private(set) array $requiredExtensions,
        private(set) array $optionalExtensions,
        private(set) string $openSwooleVersion,
        private(set) array $openSwooleFeatures,
        private(set) array $phalanxPackages,
        private(set) array $spcRegistries,
    ) {
    }

    /** @return list<string> */
    public function allExtensions(): array
    {
        return [...$this->requiredExtensions, ...$this->optionalExtensions];
    }
}
