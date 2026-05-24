<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

enum ProjectionKind: string
{
    case Activity = 'activity';
    case Conversation = 'conversation';
    case Runtime = 'runtime';
    case Workspace = 'workspace';
}
