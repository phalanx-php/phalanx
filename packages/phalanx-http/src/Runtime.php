<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\AppHost;
use Symfony\Component\Runtime\GenericRuntime;
use Symfony\Component\Runtime\RunnerInterface;

final class Runtime extends GenericRuntime
{
    private readonly string $host;
    private readonly int $port;
    private readonly float $requestTimeout;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->host = (string) ($options['host'] ?? $options['PHALANX_HOST'] ?? '0.0.0.0');
        $this->port = (int) ($options['port'] ?? $options['PHALANX_PORT'] ?? 8080);
        $this->requestTimeout = (float) ($options['request_timeout'] ?? $options['PHALANX_REQUEST_TIMEOUT'] ?? 30.0);
        parent::__construct($options);
    }

    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof PhalanxApplication) {
            return new ReactRunner(
                $application,
                $this->host,
                $this->port,
                $this->requestTimeout,
            );
        }

        if ($application instanceof AppHost) {
            return new ReactRunner(
                new PhalanxApplication($application, RouteGroup::of([])),
                $this->host,
                $this->port,
                $this->requestTimeout,
            );
        }

        return parent::getRunner($application);
    }
}
