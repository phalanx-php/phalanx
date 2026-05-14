<?php

declare(strict_types=1);

namespace Phalanx\Grammata\NativeFastPath;

use OpenSwoole\Coroutine\System;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;
use RuntimeException;

/**
 * Coroutine-native filesystem fast-path.
 *
 * Bypasses the hooked `file_get_contents`/`file_put_contents` codepath
 * in favor of OpenSwoole's explicit `Coroutine\System::readFile` and
 * `writeFile`. The hooked path works, but the explicit primitives are
 * documented as more stable and substantially faster on large assets,
 * particularly when paired with the `io_uring` reactor on Linux 5.13+.
 *
 * Use this surface when:
 *   - reading large static files (templates, JSON manifests, ML weights)
 *   - any code path where you need the fastest available read and don't
 *     need the resource semantics of stream wrappers
 *
 * For complex scenarios (reading pieces of a file, file locks, byte-
 * range I/O), continue using the existing Grammata Files/FilePool surface.
 */
final class NativeFastPath
{
    public function read(Suspendable $scope, string $path): string
    {
        $result = $scope->call(
            static fn(): string|false => System::readFile($path),
            WaitReason::custom("grammata.native.read {$path}"),
        );
        if ($result === false) {
            throw new RuntimeException("NativeFastPath::read({$path}) failed");
        }
        return $result;
    }

    public function write(Suspendable $scope, string $path, string $data, int $flags = 0): int
    {
        $result = $scope->call(
            static fn(): bool|int => System::writeFile($path, $data, $flags),
            WaitReason::custom("grammata.native.write {$path}"),
        );
        if ($result === false) {
            throw new RuntimeException("NativeFastPath::write({$path}) failed");
        }
        return is_int($result) ? $result : strlen($data);
    }
}
