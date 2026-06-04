<?php

declare(strict_types=1);

namespace Phalanx\Themis\Tests\Unit;

use Phalanx\Themis\TomlConfigSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TomlConfigSourceTest extends TestCase
{
    #[Test]
    public function missingFileReturnsEmptyArray(): void
    {
        $result = TomlConfigSource::fromFile('/nonexistent/path/phalanx.toml');

        self::assertSame([], $result);
    }

    #[Test]
    public function missingLibraryThrows(): void
    {
        if (class_exists(\PhpCollective\Toml\Toml::class)) {
            self::markTestSkipped('php-collective/toml is installed; cannot test missing-library path');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/php-collective\/toml/');

        $tmp = tempnam(sys_get_temp_dir(), 'phalanx-toml-test-');
        file_put_contents($tmp, "[scheduler]\nmax_concurrency = 8\n");

        try {
            TomlConfigSource::fromFile($tmp);
        } finally {
            unlink($tmp);
        }
    }
}
