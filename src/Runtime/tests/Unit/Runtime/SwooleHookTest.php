<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Runtime;

use Phalanx\Runtime\SwooleHook;
use PHPUnit\Framework\TestCase;

final class SwooleHookTest extends TestCase
{
    public function testSwoole62HookMapIncludesConditionalDatabaseHooks(): void
    {
        self::assertSame(2, SwooleHook::Tcp->value);
        self::assertSame(128, SwooleHook::StreamFunction->value);
        self::assertSame(65536, SwooleHook::PdoPgsql->value);
        self::assertSame(131072, SwooleHook::PdoOdbc->value);
        self::assertSame(262144, SwooleHook::PdoOracle->value);
        self::assertSame(524288, SwooleHook::PdoSqlite->value);
        self::assertSame(1048576, SwooleHook::PdoFirebird->value);
        self::assertSame(2097152, SwooleHook::NetFunction->value);
        self::assertSame(4194304, SwooleHook::MongoDb->value);
        self::assertSame(2143285247, SwooleHook::All->value);
    }

    public function testMaskNamesDoNotDoubleCountStreamSelectAliasOrAllMask(): void
    {
        self::assertSame(['STREAM_FUNCTION'], SwooleHook::namesForMask(SwooleHook::StreamFunction->value));
        self::assertContains('PDO_PGSQL', SwooleHook::namesForMask(SwooleHook::All->value));
        self::assertNotContains('ALL', SwooleHook::namesForMask(SwooleHook::All->value));
    }

    public function testRuntimeDefinedHookConstantsAreRepresented(): void
    {
        $constants = get_defined_constants(true)['swoole'] ?? [];
        $runtimeHookConstants = array_values(array_filter(
            array_keys($constants),
            static fn(string $name): bool => str_starts_with($name, 'SWOOLE_HOOK_'),
        ));

        $represented = array_map(
            static fn(SwooleHook $hook): string => $hook->constantName(),
            SwooleHook::cases(),
        );
        $represented[] = 'SWOOLE_HOOK_STREAM_SELECT';

        $missing = array_values(array_diff($runtimeHookConstants, $represented));

        self::assertSame([], $missing);
    }
}
