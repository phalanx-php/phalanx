<?php

declare(strict_types=1);

namespace Phalanx\Http\Response\Ignition;

use Phalanx\Support\PackagePaths;
use Spatie\Ignition\ErrorPage\ErrorPageViewModel;

/**
 * Custom View Model that loads assets from the Phalanx fork.
 */
final class PhalanxErrorPageViewModel extends ErrorPageViewModel
{
    #[\Override]
    public function getAssetContents(string $asset): string
    {
        $candidates = PackagePaths::ancestorCandidates(__DIR__, "resources/ignition/compiled/{$asset}");
        $assetPath = PackagePaths::firstExistingFile($candidates);

        if ($assetPath === null) {
            return "/* Asset {$asset} not found. Checked: " . implode(', ', $candidates) . ' */';
        }

        return (string) file_get_contents($assetPath);
    }

    #[\Override]
    public function solutions(): array
    {
        // Scrub Laravel-specific solutions before they are mapped to arrays
        $this->solutions = array_filter($this->solutions, static function ($solution) {
            return !str_contains($solution::class, 'Laravel') && !str_contains($solution::class, 'Spatie\\LaravelIgnition');
        });

        return parent::solutions();
    }
}
