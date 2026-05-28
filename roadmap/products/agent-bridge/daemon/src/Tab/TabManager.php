<?php

declare(strict_types=1);

namespace AgentBridge\Tab;

use AgentBridge\BridgeCommand;
use AgentBridge\BridgeConfig;
use AgentBridge\BridgeMessage;
use AgentBridge\ExtensionSession;
use AgentBridge\Lego\LegoLibrary;
use AgentBridge\Policy\PolicyStore;
use Phalanx\AppHost;
use Phalanx\Concurrency\CancellationToken;
use WeakMap;

final class TabManager
{
    /** @var array<int, TabScope> */
    private array $tabs = [];

    /** @var WeakMap<ExtensionSession, true> */
    private WeakMap $sessions;

    private ?AppHost $app = null;

    public function __construct(
        private readonly LegoLibrary $legoLibrary,
        private readonly PolicyStore $policyStore,
        private readonly BridgeConfig $config,
    ) {
        $this->sessions = new WeakMap();
    }

    /**
     * Must be called once after Application::compile() to provide the scope factory.
     *
     * TabManager depends on AppHost::createScope() for per-tab CancellationToken support.
     * AppHost cannot be injected through the service container because it is only
     * available after compile() returns. bin/bridge calls this method immediately after
     * compile() completes.
     */
    public function setApp(AppHost $app): void
    {
        $this->app = $app;
    }

    public function registerSession(ExtensionSession $session): void
    {
        $this->sessions[$session] = true;
    }

    public function unregisterSession(ExtensionSession $session): void
    {
        foreach ($session->ownedTabIds() as $tabId) {
            $this->disconnectTab($tabId);
        }
        unset($this->sessions[$session]);
    }

    public function handleTabMessage(BridgeMessage $msg, ExtensionSession $session): void
    {
        match ($msg->type) {
            'tab.connect'    => $this->connectTab($msg, $session),
            'tab.disconnect' => $this->disconnectTab($msg->tabId),
            'tab.navigate'   => $this->tabs[$msg->tabId]?->handleNavigation($msg),
            default          => null,
        };
    }

    public function handleDomMessage(BridgeMessage $msg, ExtensionSession $session): void
    {
        $this->tabs[$msg->tabId]?->inbound->emit($msg);
    }

    public function handleDomResponse(BridgeMessage $msg, ExtensionSession $session): void
    {
        $tab = $this->tabs[$msg->tabId] ?? null;
        if ($tab === null) {
            return;
        }

        $requestId = $msg->payload['requestId'] ?? null;
        if ($requestId === null) {
            return;
        }

        $deferred = $tab->pendingActions[$requestId] ?? null;
        if ($deferred === null) {
            return;
        }

        $deferred->resolve($msg->payload['elements'] ?? []);
    }

    public function handleNetMessage(BridgeMessage $msg, ExtensionSession $session): void
    {
        $this->tabs[$msg->tabId]?->inbound->emit($msg);
    }

    public function handleUserMessage(BridgeMessage $msg, ExtensionSession $session): void
    {
        match ($msg->type) {
            'user.action' => $this->tabs[$msg->tabId]?->handleUserAction($msg),
            'user.chat'   => $this->tabs[$msg->tabId]?->handleUserChat($msg),
            default       => null,
        };
    }

    public function handleActionResult(BridgeMessage $msg, ExtensionSession $session): void
    {
        $this->tabs[$msg->tabId]?->handleActionResult($msg);
    }

    public function handleFlowControl(BridgeMessage $msg, ExtensionSession $session): void
    {
        $this->tabs[$msg->tabId]?->handleFlowControl($msg);
    }

    public function tab(int $tabId): ?TabScope
    {
        return $this->tabs[$tabId] ?? null;
    }

    /** @return list<TabScope> */
    public function connectedTabs(): array
    {
        return array_values($this->tabs);
    }

    private function connectTab(BridgeMessage $msg, ExtensionSession $session): void
    {
        $tabId = $msg->tabId ?? throw new \InvalidArgumentException('tab.connect requires tabId');

        if (isset($this->tabs[$tabId])) {
            return;
        }

        $cancel = CancellationToken::create();

        // app is null only in tests that bypass setApp(). In production, bin/bridge
        // always calls setApp() before the first WebSocket connection arrives.
        $scope = $this->app !== null
            ? $this->app->createScope($cancel)
            : throw new \RuntimeException('TabManager::setApp() must be called before connectTab()');

        $tabScope = new TabScope(
            tabId: $tabId,
            url: $msg->url ?? '',
            title: $msg->title ?? '',
            domain: $msg->domain,
            session: $session,
            scope: $scope,
            cancellation: $cancel,
            legoLibrary: $this->legoLibrary,
            policyStore: $this->policyStore,
            config: $this->config,
        );

        $this->tabs[$tabId] = $tabScope;
        $session->claimTab($tabId);
        $tabScope->startPipeline();

        $session->send(BridgeCommand::uiUpdate('status', [
            'tabId' => $tabId,
            'state' => 'connected',
            'domain' => $msg->domain,
            'legoCount' => $this->legoLibrary->countForDomain($msg->domain ?? 'unknown'),
        ]));
    }

    private function disconnectTab(?int $tabId): void
    {
        if ($tabId === null) {
            return;
        }

        $tab = $this->tabs[$tabId] ?? null;
        if ($tab === null) {
            return;
        }

        unset($this->tabs[$tabId]);
        $tab->session->releaseTab($tabId);
        $tab->dispose();
    }
}
