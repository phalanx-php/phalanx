<?php

declare(strict_types=1);

namespace BgAgents;

use BgAgents\Bookkeeper\DetectionPolicy;
use BgAgents\Bookkeeper\IssueStore;
use BgAgents\Config\BgAgentsConfig;
use BgAgents\Daemon8\ObservationClient;
use BgAgents\Memory\MemoryStore;
use BgAgents\Repl\ReplDispatcher;
use BgAgents\Repl\ReplPrinter;
use BgAgents\Specialist\ContextPackBuilder;
use BgAgents\Specialist\SpecialistLoader;
use BgAgents\Specialist\SpecialistRegistry;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Service registration for bg-agents.
 *
 * Multi-arg factories accept their deps in topological (DFS) order, not
 * declaration order. To avoid that footgun on the REPL handler tree,
 * handlers are NOT registered in DI — ReplDispatcher constructs them
 * just-in-time via $scope->service() lookups at dispatch time.
 */
final class BgAgentsServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(BgAgentsConfig::class)
            ->factory(static fn(): BgAgentsConfig => BgAgentsConfig::fromContext($context));

        $services->singleton(ObservationClient::class)
            ->needs(BgAgentsConfig::class)
            ->factory(static fn(BgAgentsConfig $config): ObservationClient => new ObservationClient($config));

        $services->singleton(SpecialistLoader::class)
            ->needs(BgAgentsConfig::class)
            ->factory(static fn(BgAgentsConfig $config): SpecialistLoader => new SpecialistLoader($config->models));

        $services->singleton(SpecialistRegistry::class)
            ->needs(BgAgentsConfig::class, SpecialistLoader::class)
            ->factory(static function (BgAgentsConfig $config, SpecialistLoader $loader): SpecialistRegistry {
                return new SpecialistRegistry($loader->loadAll($config->specsDir));
            });

        $services->singleton(ContextPackBuilder::class)
            ->needs(BgAgentsConfig::class, ObservationClient::class)
            ->factory(static fn(BgAgentsConfig $_config, ObservationClient $client): ContextPackBuilder
                => new ContextPackBuilder($client));

        $services->singleton(ReplPrinter::class)
            ->factory(static fn(): ReplPrinter => new ReplPrinter());

        $services->singleton(ReplDispatcher::class)
            ->needs(ReplPrinter::class)
            ->factory(static fn(ReplPrinter $printer): ReplDispatcher => new ReplDispatcher($printer));

        $services->singleton(DetectionPolicy::class)
            ->factory(static fn(): DetectionPolicy => DetectionPolicy::fromContext($context));

        $services->singleton(IssueStore::class)
            ->needs(SwarmConfig::class, SwarmBus::class)
            ->factory(static fn(SwarmConfig $swarm, SwarmBus $bus): IssueStore => new IssueStore($bus, $swarm));

        $services->singleton(MemoryStore::class)
            ->needs(BgAgentsConfig::class, ObservationClient::class, SwarmConfig::class, SwarmBus::class)
            ->factory(static fn(
                BgAgentsConfig $_cfg,
                ObservationClient $client,
                SwarmConfig $swarm,
                SwarmBus $bus,
            ): MemoryStore => new MemoryStore($client, $bus, $swarm));
    }
}
