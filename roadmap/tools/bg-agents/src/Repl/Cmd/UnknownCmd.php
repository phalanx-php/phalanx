<?php

declare(strict_types=1);

namespace BgAgents\Repl\Cmd;

use BgAgents\Repl\ReplCommand;

final readonly class UnknownCmd implements ReplCommand
{
    public function __construct(public string $raw) {}
}
