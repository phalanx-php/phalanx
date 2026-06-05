<?php

declare(strict_types=1);

namespace Phalanx\Http\Response;

use Phalanx\Support\PackagePaths;

final class LogoResolver
{
    public static function resolve(string $logoPath): string
    {
        $path = PackagePaths::firstExistingFile(
            PackagePaths::ancestorCandidates(__DIR__, ltrim($logoPath, '/')),
        );

        if ($path === null) {
            return '';
        }

        $svg = file_get_contents($path);

        if (!$svg) {
            return '';
        }

        $svg = preg_replace('#<text.*?</text>#s', '', $svg) ?? $svg;

        return str_replace('viewBox="0 0 520 120"', 'viewBox="0 0 110 120"', $svg);
    }
}
