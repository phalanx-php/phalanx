<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Install;

use Phalanx\Cli\Install\Platform;
use Phalanx\Cli\Install\PlatformDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlatformDetectorTest extends TestCase
{
    #[Test]
    public function detectsCurrentPlatform(): void
    {
        $platform = (new PlatformDetector())();

        if (PHP_OS_FAMILY === 'Darwin') {
            self::assertSame(Platform::MacOS, $platform);
        } elseif (PHP_OS_FAMILY === 'Linux') {
            self::assertNotSame(Platform::MacOS, $platform);
        } else {
            self::assertSame(Platform::Unknown, $platform);
        }
    }
}
