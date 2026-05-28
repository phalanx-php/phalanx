<?php

declare(strict_types=1);

use BgAgents\Command\AskCommand;
use Phalanx\Archon\Arg;
use Phalanx\Archon\CommandConfig;
use Phalanx\Archon\CommandGroup;

return CommandGroup::of([
    'ask' => [AskCommand::class, new CommandConfig(
        description: 'Ask a single specialist a question (one-shot)',
        arguments: [
            Arg::required('specialist', 'Specialist name or addressing token (e.g. supervisor, @runtime)'),
            Arg::required('query', 'The question to ask, in quotes'),
        ],
    )],
]);
