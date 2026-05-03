<?php

declare(strict_types=1);

namespace Phalanx\Archon;

use Phalanx\AppHost;
use RuntimeException;
use Symfony\Component\Runtime\GenericRuntime;
use Symfony\Component\Runtime\RunnerInterface;

final class Runtime extends GenericRuntime
{
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof ArchonApplication) {
            return new ArchonRuntimeRunner($application);
        }

        if ($application instanceof AppHost) {
            throw new RuntimeException(
                'Archon runtime expects an ArchonApplication. '
                . 'Build one with Phalanx\\Archon\\Archon::starting($context).',
            );
        }

        return parent::getRunner($application);
    }
}
