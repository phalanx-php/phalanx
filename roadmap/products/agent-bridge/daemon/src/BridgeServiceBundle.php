<?php

declare(strict_types=1);

namespace AgentBridge;

use AgentBridge\Lego\LegoLibrary;
use AgentBridge\Policy\PolicyStore;
use AgentBridge\Tab\TabManager;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class BridgeServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $dataDir = $context['BRIDGE_DATA_DIR']
            ?? ($context['HOME'] ?? '/tmp') . '/.phalanx';

        $services->singleton(BridgeConfig::class)
            ->factory(static fn(): BridgeConfig => new BridgeConfig(
                dataDir: $dataDir,
                port: (int) ($context['BRIDGE_PORT'] ?? 9078),
                actionTimeoutSeconds: (float) ($context['BRIDGE_ACTION_TIMEOUT'] ?? 30.0),
                classifierBufferCount: (int) ($context['BRIDGE_CLASSIFIER_BUFFER_COUNT'] ?? 20),
                classifierBufferSeconds: (float) ($context['BRIDGE_CLASSIFIER_BUFFER_SECONDS'] ?? 2.0),
                maxEventsPerSecThrottled: (int) ($context['BRIDGE_THROTTLED_EVENTS_PER_SEC'] ?? 5),
            ));

        $services->singleton(LegoLibrary::class)
            ->factory(static fn(): LegoLibrary => new LegoLibrary(
                basePath: $dataDir . '/legos',
            ));

        $services->singleton(PolicyStore::class)
            ->factory(static fn(): PolicyStore => new PolicyStore(
                basePath: $dataDir . '/policies',
            ));

        // TabManager receives AppHost via setApp() called from bin/bridge after compile().
        // AppHost cannot be injected through the service graph -- it is only available
        // after compile() returns. All other dependencies are auto-injected singletons.
        $services->singleton(TabManager::class)
            ->factory(static fn(LegoLibrary $legoLibrary, PolicyStore $policyStore, BridgeConfig $config): TabManager => new TabManager(
                legoLibrary: $legoLibrary,
                policyStore: $policyStore,
                config: $config,
            ));
    }
}
