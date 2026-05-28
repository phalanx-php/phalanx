<?php

declare(strict_types=1);

namespace Phalanx\Agentic\Ws;

use Phalanx\Agentic\Composer\ComposerHandler;
use Phalanx\Hermes\WsGateway;
use Phalanx\Scope\ExecutionScope;

final class AgentWsGateway
{
    public function __construct(
        private readonly WsGateway $gateway,
        private readonly ComposerHandler $composer,
    ) {}

    public function handle(ExecutionScope $scope, string $route, string $payload): mixed
    {
        return $this->composer($scope, $payload);
    }
}
