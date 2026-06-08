<?php

declare(strict_types=1);

namespace Phalanx\Filesystem;

use Phalanx\Filesystem\Task\WriteFile;
use Phalanx\Scope\TaskScope;
use RuntimeException;

final class ScopedTempFile
{
    public static function write(TaskScope $scope, string $prefix, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException("Unable to create temporary file with prefix: {$prefix}");
        }

        $scope->onDispose(static function () use ($path): void {
            if (file_exists($path)) {
                unlink($path);
            }
        });

        $scope->execute(new WriteFile($path, $contents));

        return $path;
    }
}
