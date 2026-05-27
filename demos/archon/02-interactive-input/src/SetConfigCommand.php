<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\InteractiveInput;

use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * Subcommand under the `config` group. Reads two required positional args
 * and confirms the (pretend) write.
 */
final class SetConfigCommand implements Scopeable
{
    public function __invoke(CommandContext $ctx): int
    {
        $key   = (string) $ctx->args->required('key');
        $value = (string) $ctx->args->required('value');

        $ctx->service(StreamOutput::class)->persist("set {$key} = {$value}");

        return 0;
    }
}
