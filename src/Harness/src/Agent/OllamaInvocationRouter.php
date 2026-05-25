<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Athena\Router\InvocationRouter;
use Phalanx\Iris\HttpClient;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Provider\Ollama\ChatOptions;
use Phalanx\Panoply\Provider\Ollama\ChatProvider;
use Phalanx\Panoply\Transport\Iris\Transport;
use Phalanx\Scope\TaskScope;

final class OllamaInvocationRouter implements InvocationRouter
{
    public function __construct(private(set) OllamaConfig $config)
    {
    }

    public function route(TaskScope $scope, Agent $agent, Invocation $invocation): Provider
    {
        return new ChatProvider(
            transport: new Transport($scope->service(HttpClient::class), $scope),
            model: Model::of(
                name: $this->config->model,
                modelId: $this->config->model,
                aliases: ['theatron-default'],
                capabilities: Capabilities::of(
                    Capability::Reasoning,
                    Capability::Streaming,
                    Capability::ToolUse,
                ),
            ),
            baseUrl: $this->config->baseUrl,
            chatOptions: new ChatOptions(),
        );
    }
}
