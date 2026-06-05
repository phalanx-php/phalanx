<?php

declare(strict_types=1);

namespace Phalanx\Agents;

use Phalanx\Agents\Activity\GrantMonitor;
use Phalanx\Agents\Activity\Suspender;
use Phalanx\Agents\Effect\Dispatcher;
use Phalanx\Agents\Grant\MemoryGrantStore;
use Phalanx\Agents\Grant\Store as GrantStore;
use Phalanx\Agents\Grant\SurrealDbGrantStore;
use Phalanx\Agents\Hook\StepHook;
use Phalanx\Agents\Mcp\McpRegistry;
use Phalanx\Agents\Mcp\McpServer;
use Phalanx\Agents\Persistence\ExecutionStore;
use Phalanx\Agents\Persistence\MemoryExecutionStore;
use Phalanx\Agents\Persistence\SurrealDbExecutionStore;
use Phalanx\Agents\Router\InvocationRouter;
use Phalanx\Agents\Tool\ToolBundle;
use Phalanx\Agents\Tool\ToolRegistry;
use Phalanx\Agents\Turn\Builder;
use Phalanx\Agents\Turn\DefaultBuilder;
use Phalanx\Agents\Turn\RuntimeFactory;
use Phalanx\Agents\Turn\ScopedRuntimeFactory;
use Phalanx\AiProviders\Effect\Authorizer;
use Phalanx\AiProviders\Effect\Authorizer\Rules\Authorizer as RulesAuthorizer;
use Phalanx\AiProviders\Hazard\Scorer;
use Phalanx\AiProviders\Hazard\Scorer\Rules\Scorer as RulesScorer;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class AgentsBundle extends ServiceBundle
{
    /**
     * @param list<ToolBundle> $toolBundles
     * @param list<McpServer>  $mcpServers
     * @param list<StepHook>   $hooks
     */
    public function __construct(
        private(set) InvocationRouter $router,
        private(set) array $toolBundles = [],
        private(set) array $mcpServers = [],
        private(set) array $hooks = [],
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $hooks = $this->hooks;
        $router = $this->router;
        $mcpServers = $this->mcpServers;
        $toolBundles = $this->toolBundles;

        $services->singleton(ToolRegistry::class)
            ->factory(static function () use ($toolBundles): ToolRegistry {
                $registry = new ToolRegistry();
                foreach ($toolBundles as $bundle) {
                    $registry = $registry->merge($bundle);
                }

                return $registry;
            });

        $services->singleton(McpRegistry::class)
            ->factory(static fn(): McpRegistry => new McpRegistry($mcpServers));

        $services->singleton(RulesAuthorizer::class)
            ->factory(static fn(): RulesAuthorizer => new RulesAuthorizer());
        $services->alias(Authorizer::class, RulesAuthorizer::class);

        $services->singleton(RulesScorer::class)
            ->factory(static fn(): RulesScorer => new RulesScorer());
        $services->alias(Scorer::class, RulesScorer::class);

        $services->singleton(DefaultBuilder::class)
            ->factory(static fn(): DefaultBuilder => new DefaultBuilder());
        $services->alias(Builder::class, DefaultBuilder::class);

        $services->singleton(ScopedRuntimeFactory::class)
            ->factory(static fn(): ScopedRuntimeFactory => new ScopedRuntimeFactory());
        $services->alias(RuntimeFactory::class, ScopedRuntimeFactory::class);

        $services->singleton(AgentsConfig::class)
            ->factory(static fn(): AgentsConfig => new AgentsConfig($router, $hooks, $mcpServers));

        // --- Scoped (per-activity lifetime) ----------------------------------

        if ($services->has(\Phalanx\SurrealDb\Client::class)) {
            $services->scoped(SurrealDbGrantStore::class)
                ->needs(\Phalanx\SurrealDb\Client::class)
                ->factory(static fn(\Phalanx\SurrealDb\Client $surrealdb): SurrealDbGrantStore => new SurrealDbGrantStore($surrealdb));
            $services->alias(GrantStore::class, SurrealDbGrantStore::class);

            $services->scoped(SurrealDbExecutionStore::class)
                ->needs(\Phalanx\SurrealDb\Client::class)
                ->factory(static fn(\Phalanx\SurrealDb\Client $surrealdb): SurrealDbExecutionStore => new SurrealDbExecutionStore($surrealdb));
            $services->alias(ExecutionStore::class, SurrealDbExecutionStore::class);

            if ($services->has(\Phalanx\SurrealDb\Live\Connection::class)) {
                $services->scoped(Suspender::class)
                    ->needs(SurrealDbExecutionStore::class, SurrealDbGrantStore::class, \Phalanx\SurrealDb\Live\Connection::class)
                    ->factory(static fn(
                        SurrealDbExecutionStore $store,
                        SurrealDbGrantStore $grants,
                        \Phalanx\SurrealDb\Live\Connection $live,
                    ): Suspender => new Suspender($store, new GrantMonitor($live, $grants)));
            }
        } else {
            $services->scoped(MemoryGrantStore::class)
                ->factory(static fn(): MemoryGrantStore => new MemoryGrantStore());
            $services->alias(GrantStore::class, MemoryGrantStore::class);

            $services->scoped(MemoryExecutionStore::class)
                ->factory(static fn(): MemoryExecutionStore => new MemoryExecutionStore());
            $services->alias(ExecutionStore::class, MemoryExecutionStore::class);
        }

        $services->scoped(Dispatcher::class)
            ->needs(Authorizer::class, Scorer::class, GrantStore::class, ToolRegistry::class, McpRegistry::class)
            ->factory(static fn(
                Authorizer $auth,
                Scorer $scorer,
                GrantStore $grants,
                ToolRegistry $tools,
                McpRegistry $mcp,
            ): Dispatcher => new Dispatcher($auth, $scorer, $grants, $tools, $mcp));
    }
}
