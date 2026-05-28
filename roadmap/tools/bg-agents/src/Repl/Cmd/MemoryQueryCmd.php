<?php

declare(strict_types=1);

namespace BgAgents\Repl\Cmd;

use BgAgents\Repl\ReplCommand;

final readonly class MemoryQueryCmd implements ReplCommand
{
    public function __construct(public string $topic) {}
}
