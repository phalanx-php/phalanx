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

    #[Test]
    public function appNameAndEnvTableMapToContextKeys(): void
    {
        $path = $this->temporaryToml(<<<'TOML'
[app]
name = "demo-app"

[env]
HOPLITE_RANK = "strategos"
HOPLITE_SHIELD_WEIGHT = 8
TOML);

        $result = TomlConfigSource::fromFile($path);

        self::assertSame('demo-app', $result['APP_NAME']);
        self::assertSame('strategos', $result['HOPLITE_RANK']);
        self::assertSame(8, $result['HOPLITE_SHIELD_WEIGHT']);
    }

    #[Test]
    public function nestedTablesExposeBothTableAndDottedContextKeys(): void
    {
        $path = $this->temporaryToml(<<<'TOML'
[phalanx.runtime.memory]
resource_rows = 64
transition_lock_timeout = 2.5
TOML);

        $result = TomlConfigSource::fromFile($path);

        self::assertSame(64, $result['phalanx.runtime.memory']['resource_rows']);
        self::assertSame(2.5, $result['phalanx.runtime.memory.transition_lock_timeout']);
    }

    private function temporaryToml(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phalanx-toml-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
