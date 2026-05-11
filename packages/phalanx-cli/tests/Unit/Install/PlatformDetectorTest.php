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
        } else {
            self::assertNotSame(Platform::MacOS, $platform);
        }
    }

    #[Test]
    public function detectsUbuntu(): void
    {
        $content = "ID=ubuntu\nVERSION_ID=\"22.04\"\n";
        $platform = (new PlatformDetector())($content);

        self::assertSame(Platform::Debian, $platform);
    }

    #[Test]
    public function detectsDebian(): void
    {
        $content = "ID=debian\nVERSION_ID=\"12\"\n";
        $platform = (new PlatformDetector())($content);

        self::assertSame(Platform::Debian, $platform);
    }

    #[Test]
    public function detectsFedora(): void
    {
        $content = "ID=fedora\nVERSION_ID=\"39\"\n";
        $platform = (new PlatformDetector())($content);

        self::assertSame(Platform::Rhel, $platform);
    }

    #[Test]
    public function detectsAlmalinux(): void
    {
        $content = "ID=\"almalinux\"\nVERSION_ID=\"9.3\"\n";
        $platform = (new PlatformDetector())($content);

        self::assertSame(Platform::Rhel, $platform);
    }

    #[Test]
    public function detectsAlpine(): void
    {
        $content = "ID=alpine\nVERSION_ID=3.19\n";
        $platform = (new PlatformDetector())($content);

        self::assertSame(Platform::Alpine, $platform);
    }

    #[Test]
    public function fallsBackToIdLikeForDebianDerivative(): void
    {
        $content = "ID=zorin\nID_LIKE=\"ubuntu debian\"\n";
        $platform = (new PlatformDetector())($content);

        self::assertSame(Platform::Debian, $platform);
    }

    #[Test]
    public function fallsBackToIdLikeForRhelDerivative(): void
    {
        $content = "ID=nobara\nID_LIKE=\"rhel fedora\"\n";
        $platform = (new PlatformDetector())($content);

        self::assertSame(Platform::Rhel, $platform);
    }

    #[Test]
    public function unknownWithNoIdMatch(): void
    {
        $content = "NAME=\"Custom OS\"\nVERSION=1.0\n";
        $platform = (new PlatformDetector())($content);

        self::assertSame(Platform::Unknown, $platform);
    }

    #[Test]
    public function unknownForUnrecognizedDistroWithoutIdLike(): void
    {
        $content = "ID=niche_distro\nVERSION_ID=\"1.0\"\n";
        $platform = (new PlatformDetector())($content);

        self::assertSame(Platform::Unknown, $platform);
    }
}
