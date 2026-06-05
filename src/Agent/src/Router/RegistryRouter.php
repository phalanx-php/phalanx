<?php

declare(strict_types=1);

namespace Phalanx\Agent\Router;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider;
use Phalanx\AiProviders\Provider\Factory;
use Phalanx\AiProviders\Provider\Registry;
use Phalanx\AiProviders\Transport\HttpClient\Transport;
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

        $transport = new Transport($scope->service(\Phalanx\HttpClient\Client::class), $scope);
        $apiKey = $this->credentials[$resolution->config->id] ?? null;

        return Factory::create($resolution, $transport, $apiKey);
    }
}
