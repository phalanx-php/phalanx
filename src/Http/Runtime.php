<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\AppHost;
use RuntimeException;
use Symfony\Component\Runtime\GenericRuntime;
use Symfony\Component\Runtime\RunnerInterface;

final class Runtime extends GenericRuntime
{
    private readonly HttpServerConfig $serverConfig;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->serverConfig = HttpServerConfig::fromRuntimeOptions($options);
        parent::__construct($options);
    }

    #[\Override]
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof HttpApplication) {
            return new HttpRuntimeRunner(
                $application,
                $this->serverConfig,
            );
        }

        if ($application instanceof AppHost) {
            throw new RuntimeException(
                'Http runtime expects a HttpApplication. Build one with Phalanx\\Http\\Http::starting($context).'
            );
        }

        return parent::getRunner($application);
    }
}
