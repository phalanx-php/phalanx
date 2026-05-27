<?php

declare(strict_types=1);

namespace Phalanx\Cli\Doctor;

final class SwooleCheck
{
    public function __invoke(): Check
    {
        if (!extension_loaded('swoole') && !extension_loaded('openswoole')) {
            return Check::fail(
                'Swoole',
                'Not loaded',
                "Install via PIE: pie install swoole/ext-swoole\n"
                . '  Or run: phalanx swoole:install',
            );
        }

        $version = phpversion('swoole') ?: phpversion('openswoole');
        $flags = self::detectBuildFlags();
        $detail = $version !== false ? "v{$version}" : 'loaded';

        if ($flags !== []) {
            $detail .= ' (' . implode(', ', $flags) . ')';
        }

        return Check::pass('Swoole', $detail);
    }

    /** @return list<string> */
    private static function detectBuildFlags(): array
    {
        if (!class_exists(\Swoole\Constant::class)) {
            return [];
        }

        $flags = [];

        foreach ((new \ReflectionClass(\Swoole\Constant::class))->getConstants() as $name => $value) {
            if ($value !== 1) {
                continue;
            }

            if (str_starts_with($name, 'HAVE_') || str_starts_with($name, 'USE_')) {
                $flags[] = strtolower(substr($name, (int) strpos($name, '_') + 1));
            }
        }

        sort($flags);

        return $flags;
    }
}
