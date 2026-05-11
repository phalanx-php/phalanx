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
                . '  Or run: phalanx install',
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
        ob_start();
        phpinfo(INFO_MODULES);
        $info = ob_get_clean();

        if ($info === false) {
            return [];
        }

        $inSection = false;
        $flags = [];

        foreach (explode("\n", $info) as $line) {
            $trimmed = trim($line);

            if (!$inSection) {
                if (strcasecmp($trimmed, 'openswoole') === 0) {
                    $inSection = true;
                }
                continue;
            }

            if ($trimmed === '') {
                break;
            }

            if (preg_match('/^([\w][\w\s-]+?)\s+=>\s+enabled$/i', $trimmed, $m)) {
                $flags[] = strtolower(trim($m[1]));
            }
        }

        return $flags;
    }
}
