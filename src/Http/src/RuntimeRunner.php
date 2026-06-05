<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Symfony\Component\Runtime\RunnerInterface;

final readonly class RuntimeRunner implements RunnerInterface
{
    public function __construct(
        private \Phalanx\Http\Application $application,
        private ?\Phalanx\Http\ServerConfig $serverConfig = null,
    ) {
    }

    public function run(): int
    {
        return $this->application->run(fallback: $this->serverConfig);
    }
}
