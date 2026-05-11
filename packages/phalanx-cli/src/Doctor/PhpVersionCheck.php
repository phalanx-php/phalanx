<?php

declare(strict_types=1);

namespace Phalanx\Cli\Doctor;

final class PhpVersionCheck
{
    private const int MINIMUM_VERSION_ID = 80400;

    public function __invoke(): Check
    {
        $current = PHP_VERSION;

        // @phpstan-ignore greaterOrEqual.alwaysTrue (standalone installs may run on older PHP)
        if (PHP_VERSION_ID >= self::MINIMUM_VERSION_ID) {
            return Check::pass('PHP Version', "PHP {$current}");
        }

        // @phpstan-ignore deadCode.unreachable
        return Check::fail(
            'PHP Version',
            "PHP {$current} (requires >= 8.4.0)",
            'Install PHP 8.4+: https://www.php.net/downloads',
        );
    }
}
