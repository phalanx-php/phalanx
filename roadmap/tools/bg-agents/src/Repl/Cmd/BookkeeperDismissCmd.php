<?php

declare(strict_types=1);

namespace BgAgents\Repl\Cmd;

use BgAgents\Repl\ReplCommand;

final readonly class BookkeeperDismissCmd implements ReplCommand
{
    public function __construct(public int $issueId) {}
}
