<?php

declare(strict_types=1);

namespace Phalanx\Athena\Router;

use Phalanx\Iris\HttpClient;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider;
use Phalanx\Panoply\Provider\Factory;
use Phalanx\Panoply\Provider\Registry;
use Phalanx\Panoply\Transport\Iris\Transport;
use Phalanx\Scope\TaskScope;
use RuntimeException;

final class RegistryRouter implements InvocationRouter
{
    /**
     * @param array<string, string> $credentials provider-id => api-key
     */
    public function __construct(
        private(set) Registry $registry,
        private(set) string $defaultModel,
        private(set) array $credentials = [],
    ) {
    }

    public function route(TaskScope $scope, Agent $agent, Invocation $invocation): Provider
    {
        $resolution = $this->registry->byModelAlias($this->defaultModel);

        if ($resolution === null) {
            throw new RuntimeException("No provider registered for model alias '{$this->defaultModel}'");
        }

        $transport = new Transport($scope->service(HttpClient::class), $scope);
        $apiKey = $this->credentials[$resolution->config->id] ?? null;

        return Factory::create($resolution, $transport, $apiKey);
    }
}
