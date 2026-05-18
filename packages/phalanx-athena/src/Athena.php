<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Athena\Activity\Activity;
use Phalanx\Athena\Activity\Config as ActivityConfig;
use Phalanx\Athena\Activity\Result;
use Phalanx\Athena\Activity\Suspender;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Mcp\McpRegistry;
use Phalanx\Athena\Router\InvocationRouter;
use Phalanx\Athena\Tool\ToolBundle;
use Phalanx\Athena\Turn\Builder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\RuntimeFactory;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Id;
use Phalanx\Panoply\Invocation;
use Phalanx\Scope\Scope;
use Phalanx\Scope\TaskScope;

final class Athena
{
    private function __construct()
    {
    }

    public static function services(
        InvocationRouter $router,
        ToolBundle ...$toolBundles,
    ): AthenaBundle {
        return new AthenaBundle($router, array_values($toolBundles));
    }

    public static function run(
        TaskScope $scope,
        Agent $agent,
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

    private static function buildLoop(TaskScope $scope, Agent $agent, ActivityConfig $config): Loop
    {
        $athenaConfig = $scope->service(AthenaConfig::class);
        $builder = $scope->service(Builder::class);
        $runtimeFactory = $scope->service(RuntimeFactory::class);
        $dispatcher = $scope->service(Dispatcher::class);

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

        $provider = $athenaConfig->router->route($scope, $agent, $routingInvocation);
        $suspender = self::resolveSuspender($scope);

        return new Loop(
            builder: $builder,
            provider: $provider,
            runtimeFactory: $runtimeFactory,
            hooks: $athenaConfig->hooks,
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
