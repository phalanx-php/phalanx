<?php

declare(strict_types=1);

namespace Phalanx\Console\Command;

use Phalanx\AppHost;
use Phalanx\Console\Application\Application;
use Phalanx\Console\Application\RuntimeRunner;
use RuntimeException;
use Symfony\Component\Runtime\GenericRuntime;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * Symfony Runtime that returns RuntimeRunner when the application
 * closure resolves an Application. Bare AppHost instances are
 * rejected — Console entry points must build through Facade::starting()
 * so console-specific bootstrap (argv, signal policy, output streams)
 * lands in a proper Config before run().
 */
final class Runtime extends GenericRuntime
{
    #[\Override]
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof Application) {
            return new RuntimeRunner($application);
        }

        if ($application instanceof AppHost) {
            throw new RuntimeException(
                'Console runtime expects an Application. '
                . 'Build one with Phalanx\\Console\\Application\\Facade::starting($context).',
            );
        }

        return parent::getRunner($application);
    }
}
