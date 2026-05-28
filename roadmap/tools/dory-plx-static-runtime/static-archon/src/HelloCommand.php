<?php

declare(strict_types=1);

namespace App;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

final class HelloCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $ctx->service(StreamOutput::class)->persist("Hello from Phalanx Static Binary!");

        return 0;
    }
}
