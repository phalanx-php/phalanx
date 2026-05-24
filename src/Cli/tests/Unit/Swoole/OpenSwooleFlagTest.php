<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Swoole;

use Phalanx\Cli\Swoole\OpenSwooleFlag;
use Phalanx\Cli\Swoole\Platform;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenSwooleFlagTest extends TestCase
{
    #[Test]
    public function allFlagsHaveDescriptions(): void
    {
        foreach (OpenSwooleFlag::cases() as $flag) {
            self::assertNotEmpty($flag->description(), "{$flag->value} has no description");
        }
    }

    #[Test]
    public function valueBearingFlagsIdentified(): void
    {
        self::assertTrue(OpenSwooleFlag::WithOpensslDir->needsValue());
        self::assertTrue(OpenSwooleFlag::WithPostgres->needsValue());
        self::assertFalse(OpenSwooleFlag::EnableOpenssl->needsValue());
        self::assertFalse(OpenSwooleFlag::EnableSockets->needsValue());
    }

    #[Test]
    public function defaultsIncludeExpectedFlags(): void
    {
        self::assertTrue(OpenSwooleFlag::EnableOpenssl->defaultEnabled());
        self::assertTrue(OpenSwooleFlag::EnableSockets->defaultEnabled());
        self::assertTrue(OpenSwooleFlag::EnableHttp2->defaultEnabled());
        self::assertTrue(OpenSwooleFlag::EnableHookCurl->defaultEnabled());
    }

    #[Test]
    public function defaultsExcludeNonDefaultFlags(): void
    {
        self::assertFalse(OpenSwooleFlag::WithOpensslDir->defaultEnabled());
        self::assertFalse(OpenSwooleFlag::EnableMysqlnd->defaultEnabled());
        self::assertFalse(OpenSwooleFlag::WithPostgres->defaultEnabled());
        self::assertFalse(OpenSwooleFlag::EnableCares->defaultEnabled());
        self::assertFalse(OpenSwooleFlag::EnableIoUring->defaultEnabled());
    }

    #[Test]
    public function interactiveChoicesExcludesValueBearingFlags(): void
    {
        $choices = OpenSwooleFlag::interactiveChoices();

        self::assertNotContains(OpenSwooleFlag::WithOpensslDir, $choices);
        self::assertNotContains(OpenSwooleFlag::WithPostgres, $choices);
        self::assertContains(OpenSwooleFlag::EnableOpenssl, $choices);

        $excludedCount = count(array_filter(
            OpenSwooleFlag::cases(),
            static fn (OpenSwooleFlag $f): bool => $f->needsValue()
                || ($f === OpenSwooleFlag::EnableIoUring && \PHP_OS_FAMILY === 'Darwin'),
        ));
        self::assertCount(count(OpenSwooleFlag::cases()) - $excludedCount, $choices);
    }

    #[Test]
    public function opensslFlagHasSystemDepsForAllPlatforms(): void
    {
        $deps = OpenSwooleFlag::EnableOpenssl->systemDependencies();

        $platforms = array_map(
            static fn ($hint) => $hint->platform,
            $deps,
        );

        self::assertContains(Platform::MacOS, $platforms);
        self::assertContains(Platform::Debian, $platforms);
        self::assertContains(Platform::Rhel, $platforms);
        self::assertContains(Platform::Alpine, $platforms);
    }

    #[Test]
    public function ioUringExcludedFromInteractiveOnDarwin(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::assertContains(OpenSwooleFlag::EnableIoUring, OpenSwooleFlag::interactiveChoices());
            return;
        }

        self::assertNotContains(OpenSwooleFlag::EnableIoUring, OpenSwooleFlag::interactiveChoices());
    }

    #[Test]
    public function ioUringHasNoMacOSDeps(): void
    {
        $deps = OpenSwooleFlag::EnableIoUring->systemDependencies();

        $platforms = array_map(
            static fn ($hint) => $hint->platform,
            $deps,
        );

        self::assertNotContains(Platform::MacOS, $platforms);
    }

    #[Test]
    public function socketsHasNoDeps(): void
    {
        self::assertSame([], OpenSwooleFlag::EnableSockets->systemDependencies());
    }
}
