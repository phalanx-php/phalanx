<?php

declare(strict_types=1);

namespace Phalanx\Demos\Archon\InteractiveInput;

use Phalanx\Archon\Command\Arg;
use Phalanx\Archon\Command\CommandConfig;
use Phalanx\Archon\Command\CommandContext;
use Phalanx\Archon\Command\DescribesCommand;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * Subcommand under the `config` group. Reads two required positional args
 * and confirms the (pretend) write.
 */
final class SetConfigCommand implements Scopeable, DescribesCommand
{
    public static function commandConfig(): CommandConfig
    {
        return new CommandConfig(
            description: 'Set a config value.',
            arguments: [
                Arg::required('key', 'Config key.'),
                Arg::required('value', 'New value.'),
            ],
        );
    }

    public function __invoke(CommandContext $ctx): int
    {
        $key   = (string) $ctx->args->required('key');
        $value = (string) $ctx->args->required('value');

        $ctx->service(StreamOutput::class)->persist("set {$key} = {$value}");

        return 0;
    }
}
