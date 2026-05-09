<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use Phalanx\AppHost;
use Phalanx\Archon\Application\ArchonApplication;
use Phalanx\Archon\Application\ArchonRuntimeRunner;
use RuntimeException;
use Symfony\Component\Runtime\GenericRuntime;
use Symfony\Component\Runtime\RunnerInterface;

/**
 * Symfony Runtime that returns ArchonRuntimeRunner when the application
 * closure resolves an ArchonApplication. Bare AppHost instances are
 * rejected — Archon entry points must build through Archon::starting()
 * so console-specific bootstrap (argv, signal policy, output streams)
 * lands in a proper ConsoleConfig before run().
 */
final class Runtime extends GenericRuntime
{
    #[\Override]
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof ArchonApplication) {
            return new ArchonRuntimeRunner($application);
        }

        if ($application instanceof AppHost) {
            throw new RuntimeException(
                'Archon runtime expects an ArchonApplication. '
                . 'Build one with Phalanx\\Archon\\Application\\Archon::starting($context).',
            );
        }

        return parent::getRunner($application);
    }
}
