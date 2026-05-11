<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Support;

trait RemovesDirectories
{
    private static function removeDir(string $dir): void
    {
        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
