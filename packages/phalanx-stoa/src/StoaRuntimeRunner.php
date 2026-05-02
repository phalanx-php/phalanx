<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Symfony\Component\Runtime\RunnerInterface;

final readonly class StoaRuntimeRunner implements RunnerInterface
{
    public function __construct(
        private PhalanxApplication $application,
        private StoaServerConfig $serverConfig,
    ) {
    }

    public function run(): int
    {
        return StoaRunner::from(
            app: $this->application->host,
            requestTimeout: $this->serverConfig->requestTimeout,
            drainTimeout: $this->serverConfig->drainTimeout,
        )
            ->withRoutes($this->application->routes)
            ->run("{$this->serverConfig->host}:{$this->serverConfig->port}");
    }
}
