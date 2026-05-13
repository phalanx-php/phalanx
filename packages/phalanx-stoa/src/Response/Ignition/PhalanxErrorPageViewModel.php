<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response\Ignition;

use Spatie\Ignition\ErrorPage\ErrorPageViewModel;

/**
 * Custom View Model that loads assets from the Phalanx fork.
 */
final class PhalanxErrorPageViewModel extends ErrorPageViewModel
{
    #[\Override]
    public function getAssetContents(string $asset): string
    {
        $assetPath = dirname(__DIR__, 3) . "/resources/ignition/compiled/{$asset}";

        if (!is_file($assetPath)) {
            return "/* Asset {$asset} not found at {$assetPath} */";
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
