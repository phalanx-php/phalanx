<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\AppHost;
use RuntimeException;
use Symfony\Component\Runtime\GenericRuntime;
use Symfony\Component\Runtime\RunnerInterface;

final class Runtime extends GenericRuntime
{
    private readonly \Phalanx\Http\ServerConfig $serverConfig;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->serverConfig = \Phalanx\Http\ServerConfig::fromRuntimeOptions($options);
        parent::__construct($options);
    }

    #[\Override]
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof \Phalanx\Http\Application) {
            return new \Phalanx\Http\RuntimeRunner(
                $application,
                $this->serverConfig,
            );
        }

        if ($application instanceof AppHost) {
            throw new RuntimeException(
                'HTTP runtime expects a Phalanx\\Http\\Application. Build one with Phalanx\\Http\\Http::starting($context).'
            );
        }

        return parent::getRunner($application);
    }
}
