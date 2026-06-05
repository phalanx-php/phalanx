<?php

declare(strict_types=1);

namespace Phalanx\Demos\Console\InteractiveInput;

use Phalanx\Console\Command\Arg;
use Phalanx\Console\Command\CommandConfig;
use Phalanx\Console\Command\CommandContext;
use Phalanx\Console\Command\DescribesCommand;
use Phalanx\Console\Output\StreamOutput;
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
