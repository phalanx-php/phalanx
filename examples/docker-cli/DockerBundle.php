<?php

declare(strict_types=1);

use Clue\React\Docker\Client;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class DockerBundle implements ServiceBundle
{
    public function __construct(
        private readonly ?string $uri = null,
    ) {
    }

    public function services(Services $services, array $context): void
    {
        $uri = $this->uri ?? ($context['docker_uri'] ?? null);

        $services->singleton(Client::class)
            ->factory(static fn() => new Client(null, $uri));
    }
}
