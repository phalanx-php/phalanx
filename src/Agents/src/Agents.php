<?php

declare(strict_types=1);

namespace Phalanx\Agents;

use Phalanx\Agents\Activity\Activity;
use Phalanx\Agents\Activity\Config as ActivityConfig;
use Phalanx\Agents\Activity\Result;
use Phalanx\Agents\Activity\Suspender;
use Phalanx\Agents\Effect\Dispatcher;
use Phalanx\Agents\Hook\StepHook;
use Phalanx\Agents\Mcp\McpRegistry;
use Phalanx\Agents\Mcp\McpServer;
use Phalanx\Agents\Router\InvocationRouter;
use Phalanx\Agents\Tool\ToolBundle;
use Phalanx\Agents\Turn\Builder;
use Phalanx\Agents\Turn\Loop;
use Phalanx\Agents\Turn\RuntimeFactory;
use Phalanx\AiProviders\Agent as ProviderAgent;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Id;
use Phalanx\AiProviders\Invocation;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Scope\Scope;
use Phalanx\Scope\TaskScope;

final class Agents
{
    private function __construct()
    {
    }

    /**
     * @param list<ToolBundle> $toolBundles
     * @param list<McpServer>  $mcpServers
     * @param list<StepHook>   $hooks
     */
    public static function services(
        InvocationRouter $router,
        array $toolBundles = [],
        array $mcpServers = [],
        array $hooks = [],
    ): AgentsBundle {
        return new AgentsBundle($router, $toolBundles, $mcpServers, $hooks);
    }

    public static function run(
        TaskScope $scope,
        ProviderAgent $agent,
        ActivityConfig $config,
        ?Log $log = null,
    ): Result {
        $loop = self::buildLoop($scope, $agent, $config);

        return (new Activity($loop))($scope, $agent, $config, $log);
    }

    public static function mcp(Scope $scope): McpRegistry
    {
        return $scope->service(McpRegistry::class);
    }

    private static function buildLoop(TaskScope $scope, ProviderAgent $agent, ActivityConfig $config): Loop
    {
        $builder = $scope->service(Builder::class);
        $dispatcher = $scope->service(Dispatcher::class);
        $agentConfig = $scope->service(AgentsConfig::class);
        $runtimeFactory = $scope->service(RuntimeFactory::class);

        $routingInvocation = Invocation::of(
            id: 'route_' . Id::generate(),
            agentId: $agent->id,
            activityId: $config->id,
            contextHash: '',
            instructions: $agent->purpose,
            output: $agent->output,
            effects: $agent->effects,
            provider: $agent->provider,
            transport: $agent->transport,
        );

        $provider = $agentConfig->router->route($scope, $agent, $routingInvocation);
        $suspender = self::resolveSuspender($scope);

        return new Loop(
            builder: $builder,
            provider: $provider,
            runtimeFactory: $runtimeFactory,
            hooks: $agentConfig->hooks,
            dispatcher: $dispatcher,
            suspender: $suspender,
        );
    }

    private static function resolveSuspender(TaskScope $scope): ?Suspender
    {
        try {
            return $scope->service(Suspender::class);
        } catch (ServiceNotFoundException) {
            return null;
        }
    }
}
