<?php

declare(strict_types=1);

namespace Phalanx\Tui;

use Phalanx\Boot\AppContext;
use Phalanx\Tui\Collab\Apps\AgentHarnessBuilder;
use Phalanx\Tui\Tui\Apps\TuiBuilder;

final class Tui
{
    /** @param array<string,mixed> $context */
    public static function app(array $context = []): TuiBuilder
    {
        return new TuiBuilder(new AppContext($context));
    }

    /** @param array<string,mixed> $context */
    public static function agentHarness(array $context = []): AgentHarnessBuilder
    {
        return new AgentHarnessBuilder(new AppContext($context));
    }
}
