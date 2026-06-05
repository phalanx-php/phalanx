<?php

declare(strict_types=1);

namespace Phalanx\Config\Tests\Unit;

use Phalanx\Config\TomlConfigSource;
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
}
