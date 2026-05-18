<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Athena\Activity\GrantMonitor;
use Phalanx\Athena\Activity\Suspender;
use Phalanx\Athena\Effect\Dispatcher;
use Phalanx\Athena\Grant\MemoryGrantStore;
use Phalanx\Athena\Grant\Store as GrantStore;
use Phalanx\Athena\Grant\SurrealGrantStore;
use Phalanx\Athena\Hook\StepHook;
use Phalanx\Athena\Mcp\McpRegistry;
use Phalanx\Athena\Mcp\McpServer;
use Phalanx\Athena\Persistence\ExecutionStore;
use Phalanx\Athena\Persistence\MemoryExecutionStore;
use Phalanx\Athena\Persistence\SurrealExecutionStore;
use Phalanx\Athena\Router\InvocationRouter;
use Phalanx\Athena\Tool\ToolBundle;
use Phalanx\Athena\Tool\ToolRegistry;
use Phalanx\Athena\Turn\AegisRuntimeFactory;
use Phalanx\Athena\Turn\Builder;
use Phalanx\Athena\Turn\DefaultBuilder;
use Phalanx\Athena\Turn\RuntimeFactory;
use Phalanx\Boot\AppContext;
use Phalanx\Panoply\Effect\Authorizer;
use Phalanx\Panoply\Effect\Authorizer\Rules\Authorizer as RulesAuthorizer;
use Phalanx\Panoply\Hazard\Scorer;
use Phalanx\Panoply\Hazard\Scorer\Rules\Scorer as RulesScorer;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealLiveConnection;

final class AthenaBundle extends ServiceBundle
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
        $router      = $this->router;
        $toolBundles = $this->toolBundles;
        $hooks       = $this->hooks;
        $mcpServers  = $this->mcpServers;

        // --- Singletons (concrete classes; interfaces aliased below) ---------

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

        $services->singleton(AegisRuntimeFactory::class)
            ->factory(static fn(): AegisRuntimeFactory => new AegisRuntimeFactory());
        $services->alias(RuntimeFactory::class, AegisRuntimeFactory::class);

        $services->singleton(AthenaConfig::class)
            ->factory(static fn(): AthenaConfig => new AthenaConfig($router, $hooks, $mcpServers));

        // --- Scoped (per-activity lifetime) ----------------------------------

        if ($services->has(Surreal::class)) {
            $services->scoped(SurrealGrantStore::class)
                ->needs(Surreal::class)
                ->factory(static fn(Surreal $surreal): SurrealGrantStore => new SurrealGrantStore($surreal));
            $services->alias(GrantStore::class, SurrealGrantStore::class);

            $services->scoped(SurrealExecutionStore::class)
                ->needs(Surreal::class)
                ->factory(static fn(Surreal $surreal): SurrealExecutionStore => new SurrealExecutionStore($surreal));
            $services->alias(ExecutionStore::class, SurrealExecutionStore::class);

            if ($services->has(SurrealLiveConnection::class)) {
                $services->scoped(Suspender::class)
                    ->needs(SurrealExecutionStore::class, SurrealGrantStore::class, SurrealLiveConnection::class)
                    ->factory(static fn(
                        SurrealExecutionStore $store,
                        SurrealGrantStore $grants,
                        SurrealLiveConnection $live,
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
