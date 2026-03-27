<?php

declare(strict_types=1);

namespace Phalanx\Ai;

use Phalanx\Task\Executable;

interface AgentDefinition extends Executable
{
    public string $instructions { get; }

    /** @return list<class-string<\Phalanx\Ai\Tool\Tool>|\Phalanx\Ai\Tool\ToolBundle> */
    public function tools(): array;

    public function provider(): ?string;
}
