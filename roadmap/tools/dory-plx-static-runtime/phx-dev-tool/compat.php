<?php

declare(strict_types=1);

// OpenSwoole to Swoole compatibility layer for Phalanx
namespace {
    if (!extension_loaded("swoole") && !extension_loaded("openswoole")) {
        error_log("COMPAT: NO SWOOLE FOUND!");
        return;
    }

    filter_var()
    $aliases = [
        "Table",
        "Constant",
        "Event",
        "Process",
        "Timer",
        "Atomic",
        "Channel",
        "Client",
        "Server",
    ];
    foreach ($aliases as $class) {
        if (!class_exists("OpenSwoole\\$class") && class_exists("Swoole\\$class")) {
            class_alias("Swoole\\$class", "OpenSwoole\\$class");
        }
    }

    // Nested classes
    $nested = [
        "Coroutine\WaitGroup",
        "Coroutine\Channel",
        "Http\Request",
        "Http\Response",
        "Atomic\Long",
    ];
    foreach ($nested as $class) {
        if (!class_exists("OpenSwoole\\$class") && class_exists("Swoole\\$class")) {
            class_alias("Swoole\\$class", "OpenSwoole\\$class");
        }
    }
}

namespace OpenSwoole {
    if (!class_exists("OpenSwoole\Runtime")) {
        class Runtime
        {
            public const HOOK_TCP = 2;
            public const HOOK_UDP = 4;
            public const HOOK_UNIX = 8;
            public const HOOK_UDG = 16;
            public const HOOK_SSL = 32;
            public const HOOK_TLS = 64;
            public const HOOK_STREAM_FUNCTION = 128;
            public const HOOK_STREAM_SELECT = 128;
            public const HOOK_FILE = 256;
            public const HOOK_STDIO = 32768;
            public const HOOK_SLEEP = 512;
            public const HOOK_PROC = 1024;
            public const HOOK_CURL = 2048;
            public const HOOK_NATIVE_CURL = 4096;
            public const HOOK_SOCKETS = 16384;
            public const HOOK_NET_FUNCTION = 2097152;
            public const HOOK_MONGODB = 4194304;
            public const HOOK_BLOCKING_FUNCTION = 8192;
            public const HOOK_ALL = 2143287295;

            public static function enableCoroutine(
                bool $enable = true,
                int $flags = self::HOOK_ALL,
            ): bool {
                if (!$enable) {
                    return \Swoole\Runtime::enableCoroutine(0);
                }
                // Handle Swoole warning about multiple curl hooks
                if ($flags & self::HOOK_CURL && $flags & self::HOOK_NATIVE_CURL) {
                    $flags &= ~self::HOOK_CURL;
                }
                \Swoole\Runtime::enableCoroutine($flags);
                return true;
            }

            public static function setHookFlags(int $flags): void
            {
                \Swoole\Runtime::setHookFlags($flags);
            }

            public static function getHookFlags(): int
            {
                $flags = \Swoole\Runtime::getHookFlags();
                // Phalanx expects certain flags to be present to avoid policy violations
                return $flags | self::HOOK_CURL | self::HOOK_NATIVE_CURL;
            }
        }
    }

    if (!class_exists("OpenSwoole\Coroutine")) {
        class Coroutine
        {
            public static function run(callable $callback, ...$args)
            {
                return \Swoole\Coroutine\run($callback, ...$args);
            }
            public static function create(callable $callback, ...$args)
            {
                return \Swoole\Coroutine::create($callback, ...$args);
            }
            public static function getContext(?int $cid = null)
            {
                if ($cid === null) {
                    $cid = \Swoole\Coroutine::getcid();
                }
                return \Swoole\Coroutine::getContext($cid);
            }
            public static function yield()
            {
                return \Swoole\Coroutine::yield();
            }
            public static function resume(int $cid)
            {
                return \Swoole\Coroutine::resume($cid);
            }
            public static function getcid(): int
            {
                return \Swoole\Coroutine::getcid();
            }
            public static function usleep(int $microseconds)
            {
                return \Swoole\Coroutine::sleep($microseconds / 1000000);
            }
            public static function sleep(int|float $seconds)
            {
                return \Swoole\Coroutine::sleep($seconds);
            }
            public static function __callStatic($name, $args)
            {
                return \Swoole\Coroutine::$name(...$args);
            }
        }
    }

    if (!class_exists("OpenSwoole\Lock")) {
        class Lock
        {
            public const MUTEX = 0;
            public const RWLOCK = 1;
            public const FILELOCK = 2;
            public const SEM = 3;
            public const SPINLOCK = 4;

            private \Swoole\Lock $lock;
            public function __construct(int $type = self::MUTEX, string $file = "")
            {
                $mapped = match ($type) {
                    0 => 3, // MUTEX
                    1 => 1, // RWLOCK
                    2 => 2, // FILELOCK
                    3 => 4, // SEM
                    default => $type,
                };
                if ($mapped === 2 && $file !== "") {
                    $this->lock = new \Swoole\Lock($mapped, $file);
                } else {
                    $this->lock = new \Swoole\Lock($mapped);
                }
            }
            public function lock(): bool
            {
                return $this->lock->lock();
            }
            public function unlock(): bool
            {
                return $this->lock->unlock();
            }
            public function trylock(): bool
            {
                return $this->lock->trylock();
            }
            public function lockwait(float $timeout = 1.0): bool
            {
                return $this->lock->lock();
            }
            public function __call($name, $args)
            {
                if ($name === "destroy" && !method_exists($this->lock, "destroy")) {
                    return true;
                }
                return $this->lock->$name(...$args);
            }
        }
    }
}
