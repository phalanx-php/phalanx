<?php

declare(strict_types=1);

use BgAgents\Command\MemoryQueryCommand;
use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;

return CommandGroup::of([
    'memory' => [MemoryQueryCommand::class, new CommandConfig(
        description: 'Query the bg-agents long-term memory (RAG) by topic',
        arguments: [
            Arg::optional('topic', 'Topic substring to match (omit to list all)'),
        ],
    )],
]);
