<?php

declare(strict_types=1);

namespace Phalanx\Agent\Mcp;

enum Transport: string
{
    case Stdio = 'stdio';
    case Sse = 'sse';
}
