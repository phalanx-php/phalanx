<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use RuntimeException;

final class FixtureFile
{
    private function __construct()
    {
    }

    public static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read fixture file: {$path}");
        }

        return $contents;
    }
}
