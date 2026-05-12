<?php

declare(strict_types=1);

namespace Phalanx\Cli\Doctor;

final class OpenSwooleCheck
{
    public function __invoke(): Check
    {
        if (!extension_loaded('openswoole')) {
            return Check::fail(
                'OpenSwoole',
                'Not loaded',
                "Install via PIE: pie install openswoole/ext-openswoole\n"
                . '  Or run: phalanx swoole:install',
            );
        }

        $version = phpversion('openswoole');
        $flags = self::detectBuildFlags();
        $detail = $version !== false ? "v{$version}" : 'loaded';

        if ($flags !== []) {
            $detail .= ' (' . implode(', ', $flags) . ')';
        }

        return Check::pass('OpenSwoole', $detail);
    }

    /** @return list<string> */
    private static function detectBuildFlags(): array
    {
        if (!class_exists(\OpenSwoole\Constant::class)) {
            return [];
        }

        $flags = [];

        foreach ((new \ReflectionClass(\OpenSwoole\Constant::class))->getConstants() as $name => $value) {
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
