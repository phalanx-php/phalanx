<?php

declare(strict_types=1);

namespace Phalanx\Cli\Scaffold;

enum ProjectType: string
{
    case Api = 'api';
    case Console = 'console';
}
