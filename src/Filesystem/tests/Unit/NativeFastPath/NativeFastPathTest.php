<?php

declare(strict_types=1);

namespace Phalanx\Filesystem\Tests\Unit\NativeFastPath;

use Phalanx\Filesystem\NativeFastPath\NativeFastPath;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;

/**
 * Round-trips a small payload through `Coroutine\System::readFile` /
 * `writeFile`. Larger-payload throughput is exercised in benchmarks where
 * io_uring vs hooked-fread comparisons make sense; unit coverage just
 * proves the wrapper invokes the Swoole path inside a scope-supervised
 * call().
 */
final class NativeFastPathTest extends PhalanxTestCase
{
    public function testReadAndWriteRoundTrip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phalanx-native-fast');
        self::assertNotFalse($path);

        try {
            $this->scope->run(static function (ExecutionScope $scope) use ($path): void {
                $fp = new NativeFastPath();
                $written = $fp->write($scope, $path, "alpha\nbeta\n");

                self::assertGreaterThan(0, $written);
                self::assertSame("alpha\nbeta\n", $fp->read($scope, $path));
            });
        } finally {
            @unlink($path);
        }
    }
}
