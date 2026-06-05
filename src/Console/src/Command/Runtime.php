<?php

declare(strict_types=1);

namespace Phalanx\Console\Command;

use Phalanx\AppHost;
use Phalanx\Console\Application\ConsoleApplication;
use Phalanx\Console\Application\ConsoleRuntimeRunner;
use RuntimeException;
use Symfony\Component\Runtime\GenericRuntime;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * Symfony Runtime that returns ConsoleRuntimeRunner when the application
 * closure resolves an ConsoleApplication. Bare AppHost instances are
 * rejected — Console entry points must build through Console::starting()
 * so console-specific bootstrap (argv, signal policy, output streams)
 * lands in a proper ConsoleConfig before run().
 */
final class Runtime extends GenericRuntime
{
    #[\Override]
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof ConsoleApplication) {
            return new ConsoleRuntimeRunner($application);
        }

        if ($application instanceof AppHost) {
            throw new RuntimeException(
                'Console runtime expects an ConsoleApplication. '
                . 'Build one with Phalanx\\Console\\Application\\Console::starting($context).',
            );
        }

        return parent::getRunner($application);
    }
}
