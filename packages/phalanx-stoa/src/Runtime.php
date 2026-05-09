<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\AppHost;
use RuntimeException;
use Symfony\Component\Runtime\GenericRuntime;
use Symfony\Component\Runtime\RunnerInterface;

final class Runtime extends GenericRuntime
{
    private readonly StoaServerConfig $serverConfig;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->serverConfig = StoaServerConfig::fromRuntimeOptions($options);
        parent::__construct($options);
    }

    #[\Override]
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof StoaApplication) {
            return new StoaRuntimeRunner(
                $application,
                $this->serverConfig,
            );
        }

        if ($application instanceof AppHost) {
            throw new RuntimeException(
                'Stoa runtime expects a StoaApplication. Build one with Phalanx\\Stoa\\Stoa::starting($context).'
            );
        }

        return parent::getRunner($application);
    }
}
