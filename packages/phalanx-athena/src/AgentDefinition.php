<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Task\Executable;

interface AgentDefinition extends Executable
{
    public string $instructions { get; }

    /** @return list<class-string<\Phalanx\Athena\Tool\Tool>|\Phalanx\Athena\Tool\ToolBundle> */
    public function tools(): array;

    public function provider(): ?string;
}
