<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\AppHost;
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

    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof PhalanxApplication) {
            return new StoaRuntimeRunner(
                $application,
                $application->serverConfig($this->serverConfig),
            );
        }

        if ($application instanceof AppHost) {
            return new StoaRuntimeRunner(
                new PhalanxApplication($application, RouteGroup::of([])),
                $this->serverConfig,
            );
        }

        return parent::getRunner($application);
    }
}
