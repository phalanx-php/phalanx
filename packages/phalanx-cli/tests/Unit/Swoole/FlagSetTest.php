<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Swoole;

use Phalanx\Cli\Swoole\FlagSet;
use Phalanx\Cli\Swoole\OpenSwooleFlag;
use Phalanx\Cli\Swoole\Platform;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlagSetTest extends TestCase
{
    #[Test]
    public function defaultsIncludeOnlyDefaultEnabledFlags(): void
    {
        $flagSet = FlagSet::defaults();

        self::assertTrue($flagSet->contains(OpenSwooleFlag::EnableOpenssl));
        self::assertTrue($flagSet->contains(OpenSwooleFlag::EnableSockets));
        self::assertTrue($flagSet->contains(OpenSwooleFlag::EnableHttp2));
        self::assertTrue($flagSet->contains(OpenSwooleFlag::EnableHookCurl));
        self::assertFalse($flagSet->contains(OpenSwooleFlag::WithPostgres));
        self::assertFalse($flagSet->contains(OpenSwooleFlag::EnableMysqlnd));
    }

    #[Test]
    public function toPieArgsProducesCorrectFlags(): void
    {
        $flagSet = new FlagSet([
            OpenSwooleFlag::EnableOpenssl,
            OpenSwooleFlag::EnableSockets,
        ]);

        self::assertSame([
            '--enable-openssl',
            '--enable-sockets',
        ], $flagSet->toPieArgs());
    }

    #[Test]
    public function toPieArgsIncludesValuesForValueBearingFlags(): void
    {
        $flagSet = new FlagSet(
            [OpenSwooleFlag::EnableOpenssl, OpenSwooleFlag::WithOpensslDir],
            [OpenSwooleFlag::WithOpensslDir->value => '/opt/homebrew/opt/openssl'],
        );

        $args = $flagSet->toPieArgs();

        self::assertContains('--enable-openssl', $args);
        self::assertContains('--with-openssl-dir=/opt/homebrew/opt/openssl', $args);
    }

    #[Test]
    public function valueBearingFlagWithoutValueIsSkipped(): void
    {
        $flagSet = new FlagSet([OpenSwooleFlag::WithPostgres]);

        self::assertSame([], $flagSet->toPieArgs());
    }

    #[Test]
    public function isEmptyWhenNoFlags(): void
    {
        self::assertTrue((new FlagSet([]))->isEmpty());
        self::assertFalse(FlagSet::defaults()->isEmpty());
    }

    #[Test]
    public function containsChecksPresence(): void
    {
        $flagSet = new FlagSet([OpenSwooleFlag::EnableOpenssl]);

        self::assertTrue($flagSet->contains(OpenSwooleFlag::EnableOpenssl));
        self::assertFalse($flagSet->contains(OpenSwooleFlag::EnableSockets));
    }

    #[Test]
    public function systemDependenciesFiltersByPlatform(): void
    {
        $flagSet = new FlagSet([OpenSwooleFlag::EnableOpenssl]);

        $macDeps = $flagSet->systemDependenciesFor(Platform::MacOS);
        $debDeps = $flagSet->systemDependenciesFor(Platform::Debian);

        self::assertCount(1, $macDeps);
        self::assertSame('openssl', $macDeps[0]->packageName);

        self::assertCount(1, $debDeps);
        self::assertSame('libssl-dev', $debDeps[0]->packageName);
    }

    #[Test]
    public function systemDependenciesDeduplicates(): void
    {
        $flagSet = new FlagSet([
            OpenSwooleFlag::EnableOpenssl,
            OpenSwooleFlag::WithOpensslDir,
        ]);

        $deps = $flagSet->systemDependenciesFor(Platform::MacOS);

        self::assertCount(1, $deps);
    }

    #[Test]
    public function unknownPlatformReturnsNoDeps(): void
    {
        $flagSet = FlagSet::defaults();

        self::assertSame([], $flagSet->systemDependenciesFor(Platform::Unknown));
    }
}
