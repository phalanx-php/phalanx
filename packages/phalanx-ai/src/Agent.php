<?php

declare(strict_types=1);

namespace Phalanx\Ai;

use Phalanx\Ai\Message\Message;

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
