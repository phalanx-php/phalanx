<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Symfony\Component\Runtime\RunnerInterface;

final class ReactRunner implements RunnerInterface
{
    public function __construct(
        private readonly PhalanxApplication $application,
        private readonly string $host,
        private readonly int $port,
        private readonly float $requestTimeout = 30.0,
        private readonly float $drainTimeout = 30.0,
    ) {}

    public function run(): int
    {
        $runner = Runner::from($this->application->host, $this->requestTimeout, $this->drainTimeout);

        $runner->withRoutes($this->application->routes);

        foreach ($this->application->wsGroups as $wsGroup) {
            $runner->withWebsockets($wsGroup);
        }

        if ($this->application->udp !== null) {
            $runner->withUdp($this->application->udp); // @phpstan-ignore argument.type
        }

        return $runner->run("{$this->host}:{$this->port}");
    }
}
