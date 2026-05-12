<?php

declare(strict_types=1);

namespace Phalanx\Cli\Swoole;

final class PlatformDetector
{
    public function __invoke(?string $osReleaseContent = null): Platform
    {
        if ($osReleaseContent === null) {
            if (PHP_OS_FAMILY === 'Darwin') {
                return Platform::MacOS;
            }

            if (PHP_OS_FAMILY !== 'Linux') {
                return Platform::Unknown;
            }
        }

        $osRelease = $osReleaseContent ?? @file_get_contents('/etc/os-release');

        if ($osRelease === false) {
            return Platform::Unknown;
        }

        if (preg_match('/^ID=(.+)$/m', $osRelease, $m)) {
            $id = strtolower(trim($m[1], '"\''));

            return match ($id) {
                'debian', 'ubuntu', 'linuxmint', 'pop' => Platform::Debian,
                'rhel', 'fedora', 'centos', 'rocky', 'almalinux' => Platform::Rhel,
                'alpine' => Platform::Alpine,
                default => self::fromIdLike($osRelease),
            };
        }

        return Platform::Unknown;
    }

    private static function fromIdLike(string $osRelease): Platform
    {
        if (!preg_match('/^ID_LIKE=(.+)$/m', $osRelease, $m)) {
            return Platform::Unknown;
        }

        $like = strtolower(trim($m[1], '"\''));

        if (str_contains($like, 'debian') || str_contains($like, 'ubuntu')) {
            return Platform::Debian;
        }

        if (str_contains($like, 'rhel') || str_contains($like, 'fedora')) {
            return Platform::Rhel;
        }

        return Platform::Unknown;
    }
}
