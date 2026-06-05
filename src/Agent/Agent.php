<?php

declare(strict_types=1);

namespace Phalanx\Agent;

use Phalanx\Agent\Activity\Activity;
use Phalanx\Agent\Activity\Config as ActivityConfig;
use Phalanx\Agent\Activity\Result;
use Phalanx\Agent\Activity\Suspender;
use Phalanx\Agent\Effect\Dispatcher;
use Phalanx\Agent\Hook\StepHook;
use Phalanx\Agent\Mcp\McpRegistry;
use Phalanx\Agent\Mcp\McpServer;
use Phalanx\Agent\Router\InvocationRouter;
use Phalanx\Agent\Tool\ToolBundle;
use Phalanx\Agent\Turn\Builder;
use Phalanx\Agent\Turn\Loop;
use Phalanx\Agent\Turn\RuntimeFactory;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\AiProviders\Agent as ProviderAgent;
use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Id;
use Phalanx\AiProviders\Invocation;
use Phalanx\Scope\Scope;
use Phalanx\Scope\TaskScope;

final class Agent
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
    ): AgentBundle {
        return new AgentBundle($router, $toolBundles, $mcpServers, $hooks);
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
        $agentConfig = $scope->service(AgentConfig::class);
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
