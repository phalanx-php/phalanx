<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Swoole;

use Phalanx\Cli\Swoole\SwooleFlag;
use Phalanx\Cli\Swoole\Platform;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SwooleFlagTest extends TestCase
{
    #[Test]
    public function allFlagsHaveDescriptions(): void
    {
        foreach (SwooleFlag::cases() as $flag) {
            self::assertNotEmpty($flag->description(), "{$flag->value} has no description");
        }
    }

    #[Test]
    public function valueBearingFlagsIdentified(): void
    {
        self::assertTrue(SwooleFlag::WithOpensslDir->needsValue());
        self::assertTrue(SwooleFlag::WithSwooleOdbc->needsValue());
        self::assertTrue(SwooleFlag::WithSwooleOracle->needsValue());
        self::assertTrue(SwooleFlag::WithSwooleFirebird->needsValue());
        self::assertFalse(SwooleFlag::EnableOpenssl->needsValue());
        self::assertFalse(SwooleFlag::EnableSockets->needsValue());
        self::assertFalse(SwooleFlag::EnableSwoolePgsql->needsValue());
    }

    #[Test]
    public function defaultsIncludeExpectedFlags(): void
    {
        self::assertTrue(SwooleFlag::EnableOpenssl->defaultEnabled());
        self::assertTrue(SwooleFlag::EnableSockets->defaultEnabled());
        self::assertTrue(SwooleFlag::EnableHttp2->defaultEnabled());
        self::assertTrue(SwooleFlag::EnableSwooleCurl->defaultEnabled());
    }

    #[Test]
    public function defaultsExcludeNonDefaultFlags(): void
    {
        self::assertFalse(SwooleFlag::WithOpensslDir->defaultEnabled());
        self::assertFalse(SwooleFlag::EnableMysqlnd->defaultEnabled());
        self::assertFalse(SwooleFlag::EnableSwoolePgsql->defaultEnabled());
        self::assertFalse(SwooleFlag::EnableSwooleSqlite->defaultEnabled());
        self::assertFalse(SwooleFlag::WithSwooleOdbc->defaultEnabled());
        self::assertFalse(SwooleFlag::WithSwooleOracle->defaultEnabled());
        self::assertFalse(SwooleFlag::WithSwooleFirebird->defaultEnabled());
        self::assertFalse(SwooleFlag::EnableCares->defaultEnabled());
        self::assertFalse(SwooleFlag::EnableIoUring->defaultEnabled());
    }

    #[Test]
    public function interactiveChoicesExcludesValueBearingFlags(): void
    {
        $choices = SwooleFlag::interactiveChoices();

        self::assertNotContains(SwooleFlag::WithOpensslDir, $choices);
        self::assertNotContains(SwooleFlag::WithSwooleOdbc, $choices);
        self::assertNotContains(SwooleFlag::WithSwooleOracle, $choices);
        self::assertNotContains(SwooleFlag::WithSwooleFirebird, $choices);
        self::assertContains(SwooleFlag::EnableSwoolePgsql, $choices);
        self::assertContains(SwooleFlag::EnableOpenssl, $choices);

        $excludedCount = count(array_filter(
            SwooleFlag::cases(),
            static fn (SwooleFlag $f): bool => $f->needsValue()
                || ($f === SwooleFlag::EnableIoUring && \PHP_OS_FAMILY === 'Darwin'),
        ));
        self::assertCount(count(SwooleFlag::cases()) - $excludedCount, $choices);
    }

    #[Test]
    public function opensslFlagHasSystemDepsForAllPlatforms(): void
    {
        $deps = SwooleFlag::EnableOpenssl->systemDependencies();

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
            self::assertContains(SwooleFlag::EnableIoUring, SwooleFlag::interactiveChoices());

            return;
        }

        self::assertNotContains(SwooleFlag::EnableIoUring, SwooleFlag::interactiveChoices());
    }

    #[Test]
    public function ioUringHasNoMacOSDeps(): void
    {
        $deps = SwooleFlag::EnableIoUring->systemDependencies();

        $platforms = array_map(
            static fn ($hint) => $hint->platform,
            $deps,
        );

        self::assertNotContains(Platform::MacOS, $platforms);
    }

    #[Test]
    public function socketsHasNoDeps(): void
    {
        self::assertSame([], SwooleFlag::EnableSockets->systemDependencies());
    }

    #[Test]
    public function postgresqlHookFlagUsesCurrentSwooleConfigureName(): void
    {
        self::assertSame('enable-swoole-pgsql', SwooleFlag::EnableSwoolePgsql->value);
    }
}
