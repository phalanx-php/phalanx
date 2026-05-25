<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Athena\Activity\Activity;
use Phalanx\Athena\Activity\Config;
use Phalanx\Athena\AthenaBundle;
use Phalanx\Athena\AthenaConfig;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Grant\Store as GrantStore;
use Phalanx\Athena\Turn\Builder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\RuntimeFactory;
use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Effect\Authorizer;
use Phalanx\Panoply\Effect\Authorizer\Rules\Authorizer as RulesAuthorizer;
use Phalanx\Panoply\Id;
use Phalanx\Panoply\Invocation;
use Phalanx\Scope\TaskScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class AthenaServiceBundle extends ServiceBundle
{
    public function __construct(
        private(set) ?AthenaBundle $athenaBundle = null,
    ) {
    }

    public static function from(AthenaBundle $bundle): self
    {
        return new self($bundle);
    }

    public static function ollama(): self
    {
        return new self();
    }

    #[\Override]
    public static function harness(): BootHarness
    {
        return OllamaConfig::contextSchema()->harness();
    }

    public function services(Services $services, AppContext $context): void
    {
        $ollamaConfig = OllamaConfig::fromContext($context);
        $athenaBundle = $this->athenaBundle ?? new AthenaBundle(new OllamaInvocationRouter($ollamaConfig));

        $bundle = new AthenaBundle(
            router: new LlmRequestRecordingRouter($athenaBundle->router),
            toolBundles: $athenaBundle->toolBundles,
            mcpServers: $athenaBundle->mcpServers,
            hooks: $athenaBundle->hooks,
        );

        $bundle->services($services, $context);

        $services->singleton(ApprovalAuthorizer::class)
            ->needs(RulesAuthorizer::class)
            ->factory(static fn(RulesAuthorizer $inner): ApprovalAuthorizer => new ApprovalAuthorizer($inner));
        $services->alias(Authorizer::class, ApprovalAuthorizer::class);

        $services->contextConfig(OllamaConfig::class, static fn(): OllamaConfig => $ollamaConfig);

        $services->singleton(TemplateAgent::class)
            ->factory(static fn(): TemplateAgent => new TemplateAgent());
        $services->alias(Agent::class, TemplateAgent::class);

        $services->scoped(Config::class)
            ->needs(OllamaConfig::class)
            ->factory(static fn(OllamaConfig $config): Config => new Config(
                id: 'activity_' . Id::generate(),
                context: Context::new(),
                maxInvocations: $config->maxInvocations,
            ));

        $services->scoped(Activity::class)
            ->factory(static function (
                TaskScope $scope,
                Agent $agent,
                Config $config,
                Builder $builder,
                AthenaConfig $athena,
                Dispatcher $dispatcher,
                RuntimeFactory $runtimeFactory,
            ): Activity {
                $provider = $athena->router->route(
                    $scope,
                    $agent,
                    Invocation::of(
                        id: 'route_' . Id::generate(),
                        agentId: $agent->id,
                        activityId: $config->id,
                        contextHash: '',
                        instructions: $agent->purpose,
                        output: $agent->output,
                        effects: $agent->effects,
                        provider: $agent->provider,
                        transport: $agent->transport,
                    ),
                );

                return new Activity(new Loop(
                    builder: $builder,
                    provider: $provider,
                    runtimeFactory: $runtimeFactory,
                    hooks: $athena->hooks,
                    dispatcher: $dispatcher,
                ));
            });

        $services->scoped(AgentExecutor::class)
            ->factory(static fn(
                Activity $activity,
                TaskScope $scope,
                Agent $agent,
                Config $config,
                GrantStore $grantStore,
            ): AgentExecutor => new AgentExecutor($activity, $scope, $agent, $config, $grantStore));
        $services->alias(AgentExecutorContract::class, AgentExecutor::class);
    }
}
