<?php

declare(strict_types=1);

namespace Phalanx\Archon\Examples\InteractiveInput;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Archon\Console\Output\StreamOutput;
use Phalanx\Task\Scopeable;

/**
 * Subcommand under the `config` group. Reads two required positional args
 * and confirms the (pretend) write.
 */
final class SetConfigCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $key   = (string) $scope->args->required('key');
        $value = (string) $scope->args->required('value');

        $scope->service(StreamOutput::class)->persist("set {$key} = {$value}");

        return 0;
    }
}
