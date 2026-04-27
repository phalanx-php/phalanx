<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Athena\Message\Message;

final class Agent
{
    public static function from(AgentDefinition $agent): Turn
    {
        return Turn::begin($agent);
    }

    public static function quick(string $systemPrompt): Turn
    {
        return Turn::begin(new QuickAgent($systemPrompt));
    }
}
