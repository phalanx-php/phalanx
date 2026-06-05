<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Symfony\Component\Runtime\RunnerInterface;

final readonly class HttpRuntimeRunner implements RunnerInterface
{
    public function __construct(
        private HttpApplication $application,
        private ?HttpServerConfig $serverConfig = null,
    ) {
    }

    public function run(): int
    {
        return $this->application->run(fallback: $this->serverConfig);
    }
}
