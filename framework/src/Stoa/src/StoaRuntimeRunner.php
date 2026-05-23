<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Symfony\Component\Runtime\RunnerInterface;

final readonly class StoaRuntimeRunner implements RunnerInterface
{
    public function __construct(
        private StoaApplication $application,
        private ?StoaServerConfig $serverConfig = null,
    ) {
    }

    public function run(): int
    {
        return $this->application->run(fallback: $this->serverConfig);
    }
}
