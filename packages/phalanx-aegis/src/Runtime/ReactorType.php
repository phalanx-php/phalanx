<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

/**
 * OpenSwoole reactor backend selection.
 *
 * The reactor type is a server-instance setting (`$server->set(['reactor_type' => ...])`)
 * not a coroutine-global option. This enum is the typed representation Aegis
 * exposes for Stoa/Hermes/etc. server bundles to read from RuntimePolicy and
 * apply to their server config.
 *
 * Auto leaves selection to OpenSwoole's autodetect (kqueue on macOS, epoll on
 * modern Linux). IoUring requires Linux 5.13+ and OpenSwoole built with
 * io_uring support — the EnvironmentDoctor surfaces an advisory diagnostic
 * on environments where IoUring is requested but unavailable so the boot
 * doesn't proceed silently with a fallback that surprises operators.
 */
enum ReactorType: string
{
    case Auto = 'auto';
    case Epoll = 'epoll';
    case Kqueue = 'kqueue';
    case Poll = 'poll';
    case Select = 'select';
    case IoUring = 'io_uring';

    /**
     * Returns the constant value OpenSwoole's `Server::set` expects, or
     * null when Auto (caller should omit the key entirely so OpenSwoole's
     * native autodetect runs).
     */
    public function serverConfigValue(): ?string
    {
        return match ($this) {
            self::Auto => null,
            self::Epoll, self::Kqueue, self::Poll, self::Select, self::IoUring => $this->value,
        };
    }

    public function requiresLinux(): bool
    {
        return match ($this) {
            self::Epoll, self::IoUring => true,
            self::Auto, self::Kqueue, self::Poll, self::Select => false,
        };
    }

    /**
     * io_uring landed in Linux 5.1 but the polished syscall surface OpenSwoole
     * uses requires kernel >= 5.13.
     */
    public function minimumKernelVersion(): ?string
    {
        return match ($this) {
            self::IoUring => '5.13',
            self::Auto, self::Epoll, self::Kqueue, self::Poll, self::Select => null,
        };
    }
}
