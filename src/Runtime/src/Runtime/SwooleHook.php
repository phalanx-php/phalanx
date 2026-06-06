<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

enum SwooleHook: int
{
    case Tcp = 2;
    case Udp = 4;
    case Unix = 8;
    case Udg = 16;
    case Ssl = 32;
    case Tls = 64;
    case StreamFunction = 128;
    case File = 256;
    case Sleep = 512;
    case Proc = 1024;
    case Curl = 2048;
    case NativeCurl = 4096;
    case Sockets = 16384;
    case Stdio = 32768;
    case PdoPgsql = 65536;
    case PdoOdbc = 131072;
    case PdoOracle = 262144;
    case PdoSqlite = 524288;
    case PdoFirebird = 1048576;
    case NetFunction = 2097152;
    case MongoDb = 4194304;
    case All = 2143285247;

    /** @return list<self> */
    public static function maskCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn(self $hook): bool => $hook !== self::All,
        ));
    }

    /** @return list<string> */
    public static function namesForMask(int $mask): array
    {
        $names = [];
        foreach (self::maskCases() as $hook) {
            if (($mask & $hook->value) === $hook->value) {
                $names[] = $hook->label();
            }
        }

        return $names;
    }

    /** @return list<string> */
    public static function unavailableNamesForMask(int $mask): array
    {
        $names = [];
        foreach (self::maskCases() as $hook) {
            if (($mask & $hook->value) === $hook->value && !$hook->isAvailable()) {
                $names[] = $hook->label();
            }
        }

        return $names;
    }

    public static function availableMask(): int
    {
        $mask = 0;
        foreach (self::maskCases() as $hook) {
            if ($hook->isAvailable()) {
                $mask |= $hook->value;
            }
        }

        return $mask;
    }

    public function isAvailable(): bool
    {
        return defined($this->constantName());
    }

    public function constantName(): string
    {
        return match ($this) {
            self::Tcp => 'SWOOLE_HOOK_TCP',
            self::Udp => 'SWOOLE_HOOK_UDP',
            self::Unix => 'SWOOLE_HOOK_UNIX',
            self::Udg => 'SWOOLE_HOOK_UDG',
            self::Ssl => 'SWOOLE_HOOK_SSL',
            self::Tls => 'SWOOLE_HOOK_TLS',
            self::StreamFunction => 'SWOOLE_HOOK_STREAM_FUNCTION',
            self::File => 'SWOOLE_HOOK_FILE',
            self::Sleep => 'SWOOLE_HOOK_SLEEP',
            self::Proc => 'SWOOLE_HOOK_PROC',
            self::Curl => 'SWOOLE_HOOK_CURL',
            self::NativeCurl => 'SWOOLE_HOOK_NATIVE_CURL',
            self::Sockets => 'SWOOLE_HOOK_SOCKETS',
            self::Stdio => 'SWOOLE_HOOK_STDIO',
            self::PdoPgsql => 'SWOOLE_HOOK_PDO_PGSQL',
            self::PdoOdbc => 'SWOOLE_HOOK_PDO_ODBC',
            self::PdoOracle => 'SWOOLE_HOOK_PDO_ORACLE',
            self::PdoSqlite => 'SWOOLE_HOOK_PDO_SQLITE',
            self::PdoFirebird => 'SWOOLE_HOOK_PDO_FIREBIRD',
            self::NetFunction => 'SWOOLE_HOOK_NET_FUNCTION',
            self::MongoDb => 'SWOOLE_HOOK_MONGODB',
            self::All => 'SWOOLE_HOOK_ALL',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Tcp => 'TCP',
            self::Udp => 'UDP',
            self::Unix => 'UNIX',
            self::Udg => 'UDG',
            self::Ssl => 'SSL',
            self::Tls => 'TLS',
            self::StreamFunction => 'STREAM_FUNCTION',
            self::File => 'FILE',
            self::Sleep => 'SLEEP',
            self::Proc => 'PROC',
            self::Curl => 'CURL',
            self::NativeCurl => 'NATIVE_CURL',
            self::Sockets => 'SOCKETS',
            self::Stdio => 'STDIO',
            self::PdoPgsql => 'PDO_PGSQL',
            self::PdoOdbc => 'PDO_ODBC',
            self::PdoOracle => 'PDO_ORACLE',
            self::PdoSqlite => 'PDO_SQLITE',
            self::PdoFirebird => 'PDO_FIREBIRD',
            self::NetFunction => 'NET_FUNCTION',
            self::MongoDb => 'MONGODB',
            self::All => 'ALL',
        };
    }
}
