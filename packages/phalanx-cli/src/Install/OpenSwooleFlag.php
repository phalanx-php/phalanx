<?php

declare(strict_types=1);

namespace Phalanx\Cli\Install;

enum OpenSwooleFlag: string
{
    case EnableOpenssl = 'enable-openssl';
    case WithOpensslDir = 'with-openssl-dir';
    case EnableSockets = 'enable-sockets';
    case EnableHttp2 = 'enable-http2';
    case EnableMysqlnd = 'enable-mysqlnd';
    case EnableHookCurl = 'enable-hook-curl';
    case WithPostgres = 'with-postgres';
    case EnableCares = 'enable-cares';
    case EnableIoUring = 'enable-io-uring';

    public function description(): string
    {
        return match ($this) {
            self::EnableOpenssl => 'TLS/SSL support for encrypted connections',
            self::WithOpensslDir => 'Custom OpenSSL installation directory',
            self::EnableSockets => 'PHP sockets coroutine support',
            self::EnableHttp2 => 'HTTP/2 protocol support (requires nghttp2)',
            self::EnableMysqlnd => 'MySQL native driver coroutine support',
            self::EnableHookCurl => 'cURL hook for coroutine-based HTTP clients',
            self::WithPostgres => 'PostgreSQL coroutine client',
            self::EnableCares => 'Async DNS resolution via c-ares',
            self::EnableIoUring => 'io_uring support (Linux 5.19+ only)',
        };
    }

    public function needsValue(): bool
    {
        return match ($this) {
            self::WithOpensslDir, self::WithPostgres => true,
            default => false,
        };
    }

    public function defaultEnabled(): bool
    {
        return match ($this) {
            self::EnableOpenssl,
            self::EnableSockets,
            self::EnableHttp2,
            self::EnableHookCurl => true,
            default => false,
        };
    }

    /** @return list<SystemDependencyHint> */
    public function systemDependencies(): array
    {
        return match ($this) {
            self::EnableOpenssl, self::WithOpensslDir => [
                new SystemDependencyHint(Platform::MacOS, 'openssl', 'brew install openssl'),
                new SystemDependencyHint(Platform::Debian, 'libssl-dev', 'sudo apt install libssl-dev'),
                new SystemDependencyHint(Platform::Rhel, 'openssl-devel', 'sudo dnf install openssl-devel'),
                new SystemDependencyHint(Platform::Alpine, 'openssl-dev', 'apk add openssl-dev'),
            ],
            self::EnableHttp2 => [
                new SystemDependencyHint(Platform::MacOS, 'nghttp2', 'brew install nghttp2'),
                new SystemDependencyHint(Platform::Debian, 'libnghttp2-dev', 'sudo apt install libnghttp2-dev'),
                new SystemDependencyHint(Platform::Rhel, 'libnghttp2-devel', 'sudo dnf install libnghttp2-devel'),
                new SystemDependencyHint(Platform::Alpine, 'nghttp2-dev', 'apk add nghttp2-dev'),
            ],
            self::WithPostgres => [
                new SystemDependencyHint(Platform::MacOS, 'libpq', 'brew install libpq'),
                new SystemDependencyHint(Platform::Debian, 'libpq-dev', 'sudo apt install libpq-dev'),
                new SystemDependencyHint(Platform::Rhel, 'libpq-devel', 'sudo dnf install libpq-devel'),
                new SystemDependencyHint(Platform::Alpine, 'libpq-dev', 'apk add libpq-dev'),
            ],
            self::EnableCares => [
                new SystemDependencyHint(Platform::MacOS, 'c-ares', 'brew install c-ares'),
                new SystemDependencyHint(Platform::Debian, 'libc-ares-dev', 'sudo apt install libc-ares-dev'),
                new SystemDependencyHint(Platform::Rhel, 'c-ares-devel', 'sudo dnf install c-ares-devel'),
                new SystemDependencyHint(Platform::Alpine, 'c-ares-dev', 'apk add c-ares-dev'),
            ],
            self::EnableIoUring => [
                new SystemDependencyHint(Platform::Debian, 'liburing-dev', 'sudo apt install liburing-dev'),
                new SystemDependencyHint(Platform::Rhel, 'liburing-devel', 'sudo dnf install liburing-devel'),
                new SystemDependencyHint(Platform::Alpine, 'liburing-dev', 'apk add liburing-dev'),
            ],
            default => [],
        };
    }

    /** @return list<self> */
    public static function interactiveChoices(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $flag): bool => !$flag->needsValue(),
        ));
    }
}
