<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation\Record\PermissionMode;

/**
 * Permission decision recorded by the agent's permission subsystem.
 * Matches the three possible outcomes: explicit allow, interactive ask
 * (user prompted), or outright deny.
 */
enum Mode: string
{
    case Allow = 'allow';
    case Ask   = 'ask';
    case Deny  = 'deny';
}
