<?php

declare(strict_types=1);

namespace Phalanx\Harness\Agent;

use Phalanx\Athena\Activity\Activity;
use Phalanx\Athena\Activity\Config;
use Phalanx\Athena\AthenaBundle;
use Phalanx\Athena\AthenaConfig;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Grant\Store as GrantStore;
use Phalanx\Athena\Router\RegistryRouter;
use Phalanx\Athena\Turn\Builder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\RuntimeFactory;
use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Config\Config as PhalanxConfig;
use Phalanx\Config\ConfigHydrator;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Effect\Authorizer;
use Phalanx\Panoply\Effect\Authorizer\Rules\Authorizer as RulesAuthorizer;
use Phalanx\Panoply\Id;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider\Loader;
use Phalanx\Panoply\Provider\Registry;
use Phalanx\Scope\TaskScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class AthenaServiceBundle extends ServiceBundle
{
    /** @param class-string<Agent>|null $agentClass */
    public function __construct(
        private ?AthenaBundle $athenaBundle = null,
        private ?string $agentClass = null,
    ) {
    }

    /** @param class-string<Agent>|null $agentClass */
    public static function from(AthenaBundle $bundle, ?string $agentClass = null): self
    {
        return new self($bundle, $agentClass);
    }

    /** @param class-string<Agent>|null $agentClass */
    public static function ollama(?string $agentClass = null): self
    {
        return new self(agentClass: $agentClass);
    }

    #[\Override]
    public static function harness(): BootHarness
    {
        return OllamaConfig::contextSchema()->harness();
    }

    /** @return list<class-string<PhalanxConfig>> */
    #[\Override]
    public static function configs(): array
    {
        return [OllamaConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
        $ollamaConfig = ConfigHydrator::from($context)->hydrate(OllamaConfig::class);
        $athenaBundle = $this->athenaBundle ?? self::buildDefaultBundle($ollamaConfig);

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

        $services->singleton(OllamaConfig::class)
            ->factory(static fn(): OllamaConfig => $ollamaConfig);

        /** @var class-string<Agent> $concreteAgent */
        $concreteAgent = $this->agentClass ?? TemplateAgent::class;
        $services->singleton($concreteAgent)
            ->factory(static fn(): Agent => new $concreteAgent());
        $services->alias(Agent::class, $concreteAgent);

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

    private static function buildDefaultBundle(OllamaConfig $config): AthenaBundle
    {
        $ollamaYaml = dirname(__DIR__, 3) . '/Panoply/src/Provider/Ollama/ollama.panoply.yaml';
        $registry = Registry::empty()->with(Loader::fromFile($ollamaYaml));

        return new AthenaBundle(new RegistryRouter(
            registry: $registry,
            defaultModel: $config->model,
        ));
    }
}
