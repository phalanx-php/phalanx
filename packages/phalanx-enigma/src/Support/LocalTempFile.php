<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Support;

use OpenSwoole\Coroutine\System;
use Phalanx\Enigma\Exception\SshException;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\WaitReason;

final class LocalTempFile
{
    public static function write(ExecutionScope $scope, string $prefix, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid('', true);
        }

        $result = $scope->call(
            static fn(): bool|int => System::writeFile($path, $contents),
            WaitReason::custom("enigma.temp.write {$prefix}"),
        );

        if ($result === false) {
            throw new SshException("Failed to write temporary file: {$path}");
        }

        $scope->onDispose(static function () use ($path): void {
            self::delete($path);
        });

        return $path;
    }

    public static function delete(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        @unlink($path);
    }
}
