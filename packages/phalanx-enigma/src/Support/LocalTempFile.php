<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Support;

use Phalanx\Grammata\Task\WriteFile;
use Phalanx\Scope\TaskScope;

final class LocalTempFile
{
    public static function write(TaskScope $scope, string $prefix, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid('', true);
        }

        $scope->onDispose(static function () use ($path): void {
            if (file_exists($path)) {
                @unlink($path);
            }
        });

        $scope->execute(new WriteFile($path, $contents));

        return $path;
    }
}
