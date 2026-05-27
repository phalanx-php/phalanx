<?php

declare(strict_types=1);

namespace Phalanx\Dory\Command;

use Phalanx\Archon\Command\CommandScope;
use Phalanx\Dory\DoryBuilder;
use Phalanx\Task\Scopeable;

final class RunCommand implements Scopeable
{
    public function __invoke(CommandScope $scope): int
    {
        $scriptPath = (string) $scope->args->required('script');
        $resolved = realpath($scriptPath);

        if ($resolved === false || !file_exists($resolved)) {
            fwrite(STDERR, "Script not found: {$scriptPath}\n");
            return 1;
        }

        $builder = new DoryBuilder();
        $builder->script($resolved);

        return $builder->run();
    }
}
