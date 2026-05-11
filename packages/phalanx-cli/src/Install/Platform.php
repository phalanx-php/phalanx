<?php

declare(strict_types=1);

namespace Phalanx\Cli\Install;

enum Platform: string
{
    case MacOS = 'macos';
    case Debian = 'debian';
    case Rhel = 'rhel';
    case Alpine = 'alpine';
    case Unknown = 'unknown';
}
