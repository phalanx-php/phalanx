<?php

declare(strict_types=1);

namespace Phalanx\Athena\Effect;

enum Resolution: string
{
    case BuiltIn  = 'built-in';
    case LocalTool = 'local-tool';
    case McpTool   = 'mcp-tool';
    case SubAgent  = 'sub-agent';
}
