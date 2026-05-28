# Phalanx Agent Bridge

Agent-browser runtime daemon. Receives live browser state from a Chrome extension, orchestrates AI-driven automation workflows, and sends action commands back through the same channel.

Proprietary product. Consumes the Phalanx framework as a dependency.

## Why This Exists

Every browser automation tool treats the browser as a remote target you send commands to. Playwright, Puppeteer, Selenium -- command-response over CDP. The agent says "click here," waits, says "read that," waits. Synchronous conversation pretending to be browser interaction.

This inverts that relationship. A Chrome extension streams live browser state -- DOM mutations, network traffic, user actions -- out of every connected tab continuously. The Phalanx daemon receives these streams, processes them through composable operator chains (filter, throttle, correlate), and dispatches action commands back. The browser becomes a bidirectional data source, not a remote API.

The practical consequence: every website the user is logged into becomes a programmable service. No site-specific API needed. No OAuth flows. No rate limits. The extension operates inside the user's existing authenticated sessions.

### Project Structure

```
Phalanx/                          <-- workspace root
├── phalanx/                      <-- framework monorepo (dependency)
└── poc/                          <-- proof-of-concept products
    ├── aisentinel-cli/           <-- multi-agent CLI
    ├── threepath-cli/            <-- network device management
    └── agent-bridge/             <-- THIS PROJECT
    ├── daemon/                   <-- Phalanx PHP daemon
    │   ├── bin/
    │   │   └── bridge            <-- entry point
    │   ├── src/
    │   │   ├── BridgeGateway.php
    │   │   ├── BridgeMessage.php
    │   │   ├── BridgeCommand.php
    │   │   ├── BridgeConfig.php
    │   │   ├── BridgeServiceBundle.php
    │   │   ├── ExtensionSession.php
    │   │   ├── Tab/
    │   │   │   ├── TabManager.php
    │   │   │   └── TabScope.php
    │   │   ├── Lego/
    │   │   │   ├── LegoDefinition.php
    │   │   │   ├── LegoExecutor.php
    │   │   │   └── LegoLibrary.php
    │   │   ├── Policy/
    │   │   │   ├── PolicyStore.php
    │   │   │   ├── DomainPolicy.php
    │   │   │   └── PolicyRule.php
    │   │   └── Agent/
    │   │       ├── ClassifierAgent.php
    │   │       ├── GeneratorAgent.php
    │   │       └── RepairAgent.php
    │   └── composer.json
    ├── extension/                <-- Plasmo browser extension (future)
    ├── shared/                   <-- Cross-project protocol types (future)
    └── SPEC.md
```

### Namespace

```
AgentBridge\
```

### Dependencies

```json
{
    "name": "jhavenz/agent-bridge",
    "type": "project",
    "require": {
        "php": "^8.4",
        "jhavenz/phalanx": "dev-main",
        "phalanx/aegis": "^0.1",
        "phalanx/styx": "^0.1",
        "phalanx/websocket": "^0.1",
        "phalanx/stoa": "^0.1",
        "phalanx/athena": "^0.1"
    },
    "autoload": {
        "psr-4": {
            "AgentBridge\\": "src/"
        }
    }
}
```

---

## Architecture

```
Chrome Extension (Plasmo)
 ├── Side Panel (React UI)
 ├── Service Worker (message hub)
 └── Content Scripts (per-tab DOM access)
      │
      │  WebSocket ws://localhost:{port}/bridge
      │
      ▼
┌─────────────────────────────────────────────────────────┐
│                     agent-bridge                        │
│                                                         │
│  Connection Layer                                       │
│  ┌──────────────────────────────────────────────────┐   │
│  │         BridgeGateway (WsRoute)                  │   │
│  │  - accepts extension WebSocket connections        │   │
│  │  - decodes BridgeMessage from JSON frames         │   │
│  │  - routes by message type to TabManager           │   │
│  │  - sends BridgeCommand frames back                │   │
│  └──────────────────┬───────────────────────────────┘   │
│                     │                                   │
│  Tab Lifecycle      │                                   │
│  ┌──────────────────▼───────────────────────────────┐   │
│  │              TabManager (singleton)               │   │
│  │  - WeakMap<ExtensionSession, true>                │   │
│  │  - Map<tabId, TabScope>                           │   │
│  │  - connect/disconnect lifecycle                   │   │
│  │  - workflow registry                              │   │
│  └──────────┬──────────────┬────────────────────────┘   │
│             │              │                            │
│  ┌──────────▼──┐    ┌──────▼──────┐                     │
│  │  TabScope   │    │  TabScope   │  (one per tab)      │
│  │  Channel    │    │  Channel    │                      │
│  │  Stream ops │    │  Stream ops │                      │
│  │  Lego exec  │    │  Lego exec  │                      │
│  └──────┬──────┘    └──────┬──────┘                     │
│         │                  │                            │
│  Action Layer              │                            │
│  ┌──────▼──────────────────▼────────────────────────┐   │
│  │               LegoExecutor                        │   │
│  │  - takes LegoDefinition (JSON data)               │   │
│  │  - converts steps to BridgeCommand messages       │   │
│  │  - sends through WsConnection                     │   │
│  │  - awaits action.result per step                  │   │
│  └──────────────────────────────────────────────────┘   │
│                                                         │
│  Intelligence Layer                                     │
│  ┌──────────────────────────────────────────────────┐   │
│  │   ClassifierAgent     │   GeneratorAgent          │   │
│  │   (phalanx-athena)        │   (phalanx-athena)            │   │
│  │   - DOM → lego plan   │   - DOM → new legos       │   │
│  │   - cheap, frequent   │   - expensive, rare       │   │
│  └──────────────────────────────────────────────────┘   │
│                                                         │
│  Storage Layer                                          │
│  ┌──────────────────────────────────────────────────┐   │
│  │   LegoLibrary         │   PolicyStore             │   │
│  │   ~/.phalanx/legos/   │   ~/.phalanx/policies/    │   │
│  └──────────────────────────────────────────────────┘   │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

### Transport

The extension connects via WebSocket to the daemon's HTTP server. The daemon uses phalanx-websocket infrastructure -- `WsRouteGroup` handles the upgrade, `WsConnectionHandler` manages the codec and channels, `WsGateway` tracks connections.

Why WebSocket over Native Messaging as primary transport:
- No message size limit (Native Messaging caps outbound at 1MB)
- Bidirectional backpressure via WebSocket flow control
- No relay binary installation required
- The daemon already has a WebSocket server

Native Messaging serves a secondary role: the extension uses `chrome.runtime.connectNative()` to discover the daemon's port and keep the service worker alive. The Native Messaging host is a ~20-line script that reads the daemon's port from a lockfile and responds with the WebSocket URL.

### Connection Lifecycle

```
1. User installs extension + daemon
2. Daemon starts → listens on ws://localhost:{port}/bridge
3. Daemon writes port to ~/.phalanx/daemon.lock
4. Extension service worker starts
5. Extension reads port via Native Messaging bootstrap
6. Extension connects WebSocket to daemon
7. Daemon creates ExtensionSession for the connection
8. User clicks "Connect" on a tab in the side panel
9. Extension activates content script on that tab
10. Content script starts observing DOM, sends tab.connect
11. Daemon creates TabScope for the tab
12. Streaming begins: DOM events flow in, action commands flow out
13. User clicks "Disconnect" or closes the tab
14. Extension sends tab.disconnect (or WS connection drops)
15. Daemon disposes TabScope → cancels fibers, cleans up
```

---

## Wire Protocol

All messages are JSON objects with a `type` field and a flat payload. No envelope wrappers, no versioning headers. If the protocol evolves, new message types are added -- existing ones are never modified.

### Extension → Daemon

#### Tab Lifecycle

```json
{"type": "tab.connect", "tabId": 42, "url": "https://mail.google.com/mail/", "title": "Gmail", "domain": "mail.google.com"}
```

```json
{"type": "tab.disconnect", "tabId": 42}
```

```json
{"type": "tab.navigate", "tabId": 42, "url": "https://mail.google.com/mail/#inbox", "title": "Inbox - Gmail"}
```

#### DOM Events

```json
{
    "type": "dom.snapshot",
    "tabId": 42,
    "html": "<div class=\"inbox\">...</div>",
    "selector": ".inbox",
    "timestamp": 1712188800000
}
```

Snapshots are scoped by CSS selector -- never full-page HTML. The daemon or AI requests specific regions via `dom.request`. The content script also sends snapshots on initial connect and after navigation.

```json
{
    "type": "dom.mutations",
    "tabId": 42,
    "mutations": [
        {"type": "childList", "target": ".email-list", "addedCount": 3, "removedCount": 0},
        {"type": "attributes", "target": ".unread-count", "attr": "data-count", "value": "47"}
    ],
    "timestamp": 1712188801000
}
```

Mutations are summarized by the content script -- not raw `MutationRecord` objects. The content script reduces noise: filters invisible elements, debounces rapid mutations, and batches by animation frame.

#### Network Events

```json
{
    "type": "net.request",
    "tabId": 42,
    "requestId": "req_1",
    "method": "GET",
    "url": "https://mail.google.com/mail/u/0/h/api/",
    "timestamp": 1712188802000
}
```

```json
{
    "type": "net.response",
    "tabId": 42,
    "requestId": "req_1",
    "status": 200,
    "contentType": "application/json",
    "bodyPreview": "{\"threads\":[...]}",
    "durationMs": 142,
    "timestamp": 1712188802142
}
```

Network events come from the service worker's `chrome.webRequest` or `declarativeNetRequest` listeners. `bodyPreview` is the first 4KB of the response body (when available and text-based). Full bodies are not sent unless explicitly requested.

#### User Actions

```json
{
    "type": "user.action",
    "tabId": 42,
    "action": "click",
    "target": "button.archive",
    "value": null,
    "timestamp": 1712188803000
}
```

The content script observes user interactions to build behavioral signals. These feed the policy learning engine -- when the user manually archives an email, the daemon learns that email matched the user's archive criteria.

#### Action Results

```json
{
    "type": "action.result",
    "tabId": 42,
    "actionId": "act_7",
    "success": true,
    "data": {"textContent": "Archived 3 conversations"}
}
```

```json
{
    "type": "action.result",
    "tabId": 42,
    "actionId": "act_8",
    "success": false,
    "error": "Element not found: .compose-button"
}
```

#### Flow Control

```json
{"type": "flow.pressure", "tabId": 42, "bufferDepth": 47}
```

The content script tracks its own outbound buffer depth. When the WebSocket is congested (messages backing up), it reports pressure. The daemon can respond with `flow.throttle`.

### Daemon → Extension

#### Action Commands

```json
{
    "type": "action.execute",
    "tabId": 42,
    "actionId": "act_7",
    "steps": [
        {"op": "click", "selector": "[data-tooltip='Archive']"},
        {"op": "waitForRemoval", "selector": ".email-row.selected", "timeoutMs": 5000}
    ]
}
```

Each step is a primitive DOM operation. The content script executes them sequentially, reporting `action.result` when the sequence completes or fails.

```json
{"type": "action.cancel", "tabId": 42, "actionId": "act_7"}
```

#### DOM Requests

```json
{
    "type": "dom.request",
    "tabId": 42,
    "requestId": "dreq_1",
    "selector": ".email-list .email-row",
    "attrs": ["data-message-id", "data-sender", "data-subject"],
    "limit": 50
}
```

Explicit request for structured DOM data. The content script queries and responds with:

```json
{
    "type": "dom.response",
    "tabId": 42,
    "requestId": "dreq_1",
    "elements": [
        {"data-message-id": "msg_1", "data-sender": "noreply@github.com", "data-subject": "PR Review"},
        {"data-message-id": "msg_2", "data-sender": "team@linear.app", "data-subject": "DEV-1234"}
    ]
}
```

This is how the daemon proactively queries the DOM rather than waiting for mutation events. The classifier agent uses this to get a structured view of the page.

#### UI Updates

```json
{
    "type": "ui.update",
    "target": "status",
    "data": {"tabId": 42, "state": "connected", "legoCount": 12}
}
```

```json
{
    "type": "ui.update",
    "target": "confidence",
    "data": {"tabId": 42, "action": "archive", "confidence": 0.94, "executions": 312, "overrides": 4}
}
```

```json
{
    "type": "ui.update",
    "target": "conversation",
    "data": {"tabId": 42, "role": "agent", "text": "I see 47 emails matching your archive pattern. Execute?"}
}
```

UI updates flow to the side panel via the service worker. The side panel renders them directly -- no additional processing.

#### Flow Control

```json
{"type": "flow.throttle", "tabId": 42, "maxEventsPerSec": 10}
```

```json
{"type": "flow.resume", "tabId": 42}
```

When the daemon's stream pipeline is overwhelmed, it throttles the source. The content script reduces its observation frequency. `flow.resume` lifts the restriction.

---

## Action Step Operations

The `steps` array in `action.execute` messages uses a fixed set of primitive operations. These are the atoms -- legos compose them into sequences.

| Op | Fields | Behavior |
|----|--------|----------|
| `click` | `selector` | querySelector, scrollIntoView, click() |
| `clickAll` | `selector`, `delayMs?` | querySelectorAll, click each with optional delay between |
| `type` | `selector`, `value` | focus element, clear existing, type value character by character |
| `fill` | `selector`, `value` | focus element, set value property directly, dispatch input+change events |
| `select` | `selector`, `value` | set select element's value, dispatch change event |
| `check` | `selector`, `checked` | set checkbox/radio checked state |
| `press` | `key` | dispatch keydown+keypress+keyup for a key (e.g., "Enter", "Escape", "Tab") |
| `scroll` | `selector`, `x?`, `y?` | scroll element to coordinates or scroll into view if no coords |
| `waitForSelector` | `selector`, `timeoutMs?` | poll until element exists in DOM, default 5000ms timeout |
| `waitForRemoval` | `selector`, `timeoutMs?` | poll until element no longer exists |
| `waitForText` | `selector`, `text`, `timeoutMs?` | poll until element's textContent contains text |
| `waitForNetwork` | `urlPattern`, `timeoutMs?` | wait for a network request matching pattern to complete |
| `getAttribute` | `selector`, `attr` | return element's attribute value |
| `getTextContent` | `selector` | return element's textContent |
| `evaluate` | `expression` | execute arbitrary JS expression, return result (escape hatch) |
| `delay` | `ms` | sleep for N milliseconds |

The content script implements each op as a standalone function. A switch statement dispatches by `op` string.

---

## Core Infrastructure

### BridgeMessage

Inbound message from the extension. Decoded from JSON by the gateway.

```php
<?php

declare(strict_types=1);

namespace AgentBridge;

final class BridgeMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public private(set) string $type,
        public private(set) ?int $tabId = null,
        public private(set) ?string $url = null,
        public private(set) ?string $title = null,
        public private(set) ?string $domain = null,
        /** @var array<string, mixed> */
        public private(set) array $payload = [],
        public private(set) ?float $timestamp = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromJson(array $data): self
    {
        $type = $data['type'] ?? throw new \InvalidArgumentException('Missing message type');
        $url = $data['url'] ?? null;

        // domain from wire takes precedence; fall back to parsing url
        $domain = $data['domain'] ?? null;
        if ($domain === null && $url !== null) {
            $domain = parse_url($url, PHP_URL_HOST) ?: null;
        }

        return new self(
            type: $type,
            tabId: isset($data['tabId']) ? (int) $data['tabId'] : null,
            url: $url,
            title: $data['title'] ?? null,
            domain: $domain,
            payload: array_diff_key($data, array_flip(['type', 'tabId', 'url', 'title', 'domain', 'timestamp'])),
            timestamp: isset($data['timestamp']) ? (float) $data['timestamp'] : null,
        );
    }
}
```

### BridgeCommand

Outbound message to the extension. Serialized to JSON by the gateway.

```php
<?php

declare(strict_types=1);

namespace AgentBridge;

final readonly class BridgeCommand
{
    public function __construct(
        public string $type,
        public ?int $tabId = null,
        public ?string $actionId = null,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {}

    public function toJson(): string
    {
        return json_encode(
            array_filter(
                ['type' => $this->type, 'tabId' => $this->tabId, 'actionId' => $this->actionId, ...$this->payload],
                static fn(mixed $v): bool => $v !== null,
            ),
            JSON_THROW_ON_ERROR,
        );
    }

    public static function executeAction(int $tabId, string $actionId, array $steps): self
    {
        return new self('action.execute', $tabId, $actionId, ['steps' => $steps]);
    }

    public static function cancelAction(int $tabId, string $actionId): self
    {
        return new self('action.cancel', $tabId, $actionId);
    }

    public static function requestDom(int $tabId, string $requestId, string $selector, ?array $attrs = null, ?int $limit = null): self
    {
        return new self('dom.request', $tabId, payload: array_filter([
            'requestId' => $requestId,
            'selector' => $selector,
            'attrs' => $attrs,
            'limit' => $limit,
        ], static fn(mixed $v): bool => $v !== null));
    }

    public static function uiUpdate(string $target, array $data): self
    {
        return new self('ui.update', payload: ['target' => $target, 'data' => $data]);
    }

    public static function throttle(int $tabId, int $maxEventsPerSec): self
    {
        return new self('flow.throttle', $tabId, payload: ['maxEventsPerSec' => $maxEventsPerSec]);
    }

    public static function resume(int $tabId): self
    {
        return new self('flow.resume', $tabId);
    }
}
```

### BridgeGateway

WebSocket route handler. Accepts extension connections, decodes messages, routes to TabManager.

```php
<?php

declare(strict_types=1);

namespace AgentBridge;

use AgentBridge\Tab\TabManager;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsConnection;
use Phalanx\WebSocket\WsScope;

final class BridgeGateway implements Scopeable
{
    public function __invoke(\Phalanx\Scope $scope): mixed
    {
        assert($scope instanceof WsScope);
        $conn = $scope->connection;
        $tabManager = $scope->service(TabManager::class);
        $session = new ExtensionSession($conn);

        $tabManager->registerSession($session);

        $scope->onDispose(static function () use ($tabManager, $session): void {
            $tabManager->unregisterSession($session);
        });

        self::pump($scope, $conn, $tabManager, $session);

        return null;
    }

    private static function pump(
        WsScope $scope,
        WsConnection $conn,
        TabManager $tabManager,
        ExtensionSession $session,
    ): void {
        foreach ($conn->inbound->consume() as $frame) {
            $scope->throwIfCancelled();

            try {
                $data = json_decode($frame->payload, true, 2048, JSON_THROW_ON_ERROR);
                $msg = BridgeMessage::fromJson($data);
            } catch (\Throwable) {
                continue;
            }

            // dom.response MUST precede the dom.* prefix match -- it is a request-reply,
            // not a stream event, and must be routed to its pending Deferred by requestId.
            match (true) {
                $msg->type === 'dom.response'              => $tabManager->handleDomResponse($msg, $session),
                str_starts_with($msg->type, 'tab.')        => $tabManager->handleTabMessage($msg, $session),
                str_starts_with($msg->type, 'dom.')        => $tabManager->handleDomMessage($msg, $session),
                str_starts_with($msg->type, 'net.')        => $tabManager->handleNetMessage($msg, $session),
                str_starts_with($msg->type, 'user.')       => $tabManager->handleUserMessage($msg, $session),
                str_starts_with($msg->type, 'action.')     => $tabManager->handleActionResult($msg, $session),
                str_starts_with($msg->type, 'flow.')       => $tabManager->handleFlowControl($msg, $session),
                default                                    => null,
            };
        }
    }
}
```

### ExtensionSession

Represents one connected browser extension instance. Owns the WebSocket connection and tracks which tabs belong to this session.

```php
<?php

declare(strict_types=1);

namespace AgentBridge;

use Phalanx\WebSocket\WsConnection;

final class ExtensionSession
{
    /** @var array<int, true> */
    private array $tabs = [];

    public function __construct(
        public private(set) WsConnection $connection,
    ) {}

    public function send(BridgeCommand $command): void
    {
        $this->connection->sendText($command->toJson());
    }

    public function claimTab(int $tabId): void
    {
        $this->tabs[$tabId] = true;
    }

    public function releaseTab(int $tabId): void
    {
        unset($this->tabs[$tabId]);
    }

    /** @return list<int> */
    public function ownedTabIds(): array
    {
        return array_keys($this->tabs);
    }
}
```

---

## Tab Lifecycle

### TabManager

Singleton service. Manages all connected tabs across all extension sessions.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Tab;

use AgentBridge\BridgeCommand;
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
    ) {
        $this->sessions = new WeakMap();
    }

    /**
     * Must be called once after Application::compile() to provide the scope factory.
     *
     * AppHost cannot be injected through the service container because it is only
     * available after compile() returns. bin/bridge calls this method immediately
     * after compile() completes.
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
            'user.chat'   => null, // Phase 4: GeneratorAgent invocation
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
        $scope = $this->app?->createScope($cancel)
            ?? throw new \RuntimeException('TabManager::setApp() must be called before connectTab()');

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
        );

        $this->tabs[$tabId] = $tabScope;
        $session->claimTab($tabId);

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
```

### TabScope

One per connected tab. Owns the inbound event channel, the stream pipeline, and active lego executions. Disposal is the cleanup mechanism -- no explicit state machine.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Tab;

use AgentBridge\BridgeCommand;
use AgentBridge\BridgeMessage;
use AgentBridge\ExtensionSession;
use AgentBridge\Lego\LegoExecutor;
use AgentBridge\Lego\LegoLibrary;
use AgentBridge\Policy\PolicyStore;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\ExecutionScope;
use Phalanx\Stream\Channel;
use React\Promise\Deferred;

final class TabScope
{
    public readonly Channel $inbound;

    /**
     * Keyed by actionId or requestId.
     *
     * @var array<string, Deferred>
     */
    public array $pendingActions = [];

    private int $nextActionId = 1;

    public function __construct(
        public readonly int $tabId,
        public private(set) string $url,
        public private(set) string $title,
        public readonly ?string $domain,
        public readonly ExtensionSession $session,
        private readonly ExecutionScope $scope,
        private readonly CancellationToken $cancellation,
        private readonly LegoLibrary $legoLibrary,
        private readonly PolicyStore $policyStore,
    ) {
        $this->inbound = new Channel(bufferSize: 64);
    }

    /**
     * Execute action steps on this tab.
     *
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    public function executeAction(array $steps): array
    {
        $actionId = 'act_' . $this->nextActionId++;
        $deferred = new Deferred();
        $this->pendingActions[$actionId] = $deferred;

        $this->session->send(BridgeCommand::executeAction($this->tabId, $actionId, $steps));

        try {
            return $this->scope->await($deferred->promise());
        } finally {
            unset($this->pendingActions[$actionId]);
        }
    }

    /**
     * Request structured DOM data from the content script.
     *
     * @param list<string>|null $attrs
     * @return list<array<string, string>>
     */
    public function queryDom(string $selector, ?array $attrs = null, ?int $limit = null): array
    {
        $requestId = 'dreq_' . $this->nextActionId++;
        $deferred = new Deferred();
        $this->pendingActions[$requestId] = $deferred;

        $this->session->send(BridgeCommand::requestDom($this->tabId, $requestId, $selector, $attrs, $limit));

        try {
            return $this->scope->await($deferred->promise());
        } finally {
            unset($this->pendingActions[$requestId]);
        }
    }

    public function handleNavigation(BridgeMessage $msg): void
    {
        $this->url = $msg->url ?? $this->url;
        $this->title = $msg->title ?? $this->title;
    }

    public function handleUserAction(BridgeMessage $msg): void
    {
        $this->policyStore->recordUserAction($this->domain ?? 'unknown', $msg->payload);
        $this->inbound->emit($msg);
    }

    public function handleActionResult(BridgeMessage $msg): void
    {
        $id = $msg->payload['actionId'] ?? null;
        if ($id === null) {
            return;
        }

        $deferred = $this->pendingActions[$id] ?? null;
        if ($deferred === null) {
            return;
        }

        if ($msg->payload['success'] ?? false) {
            $deferred->resolve($msg->payload['data'] ?? []);
        } else {
            $deferred->reject(new \RuntimeException($msg->payload['error'] ?? 'Action failed'));
        }
    }

    public function handleFlowControl(BridgeMessage $msg): void
    {
        // Extension reporting buffer pressure.
        // Channel backpressure handles daemon-side congestion.
    }

    public function executor(): LegoExecutor
    {
        return new LegoExecutor($this);
    }

    public function say(string $text): void
    {
        $this->session->send(BridgeCommand::uiUpdate('conversation', [
            'tabId' => $this->tabId,
            'role' => 'agent',
            'text' => $text,
        ]));
    }

    public function dispose(): void
    {
        foreach ($this->pendingActions as $deferred) {
            $deferred->reject(new \RuntimeException('Tab disconnected'));
        }
        $this->pendingActions = [];
        $this->inbound->complete();
        $this->cancellation->cancel();
        $this->scope->dispose();
    }
}
```

---

## Lego System

Legos are named, composable action sequences stored as JSON. They represent compiled knowledge of how to interact with a specific site. The AI generates them; the executor runs them; the library stores them.

### LegoDefinition

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Lego;

final readonly class LegoDefinition
{
    public float $confidence {
        get {
            if ($this->executions === 0) {
                return 0.0;
            }
            $overrideRate = $this->overrides / $this->executions;
            return max(0.0, min(1.0, 1.0 - ($overrideRate * 2)));
        }
    }

    public function __construct(
        public string $name,
        public string $domain,
        public string $description,
        /** @var list<array{op: string, selector?: string, value?: string, timeoutMs?: int}> */
        public array $steps,
        public int $executions = 0,
        public int $overrides = 0,
        public int $failures = 0,
        public ?string $lastVerified = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            domain: $data['domain'],
            description: $data['description'] ?? '',
            steps: $data['steps'],
            executions: $data['executions'] ?? 0,
            overrides: $data['overrides'] ?? 0,
            failures: $data['failures'] ?? 0,
            lastVerified: $data['lastVerified'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'domain' => $this->domain,
            'description' => $this->description,
            'steps' => $this->steps,
            'executions' => $this->executions,
            'overrides' => $this->overrides,
            'failures' => $this->failures,
            'lastVerified' => $this->lastVerified,
        ];
    }

    public function withExecution(bool $succeeded): self
    {
        return new self(
            $this->name, $this->domain, $this->description, $this->steps,
            $this->executions + 1, $this->overrides,
            $this->failures + ($succeeded ? 0 : 1), date('c'),
        );
    }

    public function withOverride(): self
    {
        return new self(
            $this->name, $this->domain, $this->description, $this->steps,
            $this->executions, $this->overrides + 1,
            $this->failures, $this->lastVerified,
        );
    }

    public function withRepairedSteps(array $steps): self
    {
        return new self(
            $this->name, $this->domain, $this->description, $steps,
            $this->executions, $this->overrides, $this->failures, null,
        );
    }
}
```

### LegoExecutor

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Lego;

use AgentBridge\Tab\TabScope;

final class LegoExecutor
{
    public function __construct(
        private readonly TabScope $tab,
    ) {}

    public function execute(LegoDefinition $lego, LegoLibrary $library): bool
    {
        try {
            $this->tab->executeAction($lego->steps);
            $library->save($lego->withExecution(succeeded: true));
            return true;
        } catch (\Throwable) {
            $library->save($lego->withExecution(succeeded: false));
            return false;
        }
    }

    /** @param list<LegoDefinition> $legos */
    public function executeBatch(array $legos, LegoLibrary $library): int
    {
        $succeeded = 0;
        foreach ($legos as $lego) {
            if ($this->execute($lego, $library)) {
                $succeeded++;
            }
        }
        return $succeeded;
    }
}
```

### LegoLibrary

File-based storage. One directory per domain, one JSON file per lego.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Lego;

final class LegoLibrary
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    /** @return list<LegoDefinition> */
    public function forDomain(string $domain): array
    {
        $dir = $this->domainPath($domain);
        if (!is_dir($dir)) {
            return [];
        }

        $legos = [];
        foreach (glob("{$dir}/*.json") as $file) {
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $legos[] = LegoDefinition::fromArray($data);
        }

        return $legos;
    }

    public function get(string $domain, string $name): ?LegoDefinition
    {
        $file = $this->legoPath($domain, $name);
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        return LegoDefinition::fromArray($data);
    }

    public function save(LegoDefinition $lego): void
    {
        $dir = $this->domainPath($lego->domain);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->legoPath($lego->domain, $lego->name),
            json_encode($lego->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }

    public function delete(string $domain, string $name): void
    {
        $file = $this->legoPath($domain, $name);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function countForDomain(string $domain): int
    {
        $dir = $this->domainPath($domain);
        if (!is_dir($dir)) {
            return 0;
        }
        return count(glob("{$dir}/*.json"));
    }

    private function domainPath(string $domain): string
    {
        return $this->basePath . '/' . str_replace(['/', '\\', '..'], '_', $domain);
    }

    private function legoPath(string $domain, string $name): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
        return $this->domainPath($domain) . "/{$safeName}.json";
    }
}
```

---

## Policy System

Policies are per-domain classification rules that the agent learns from user behavior.

### PolicyStore

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Policy;

final class PolicyStore
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function forDomain(string $domain): DomainPolicy
    {
        $file = $this->policyPath($domain);
        if (!file_exists($file)) {
            return DomainPolicy::empty($domain);
        }

        $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        return DomainPolicy::fromArray($data);
    }

    public function save(DomainPolicy $policy): void
    {
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }

        file_put_contents(
            $this->policyPath($policy->domain),
            json_encode($policy->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }

    public function recordUserAction(string $domain, array $action): void
    {
        $policy = $this->forDomain($domain);
        $this->save($policy->withUserAction($action));
    }

    public function recordOverride(string $domain, string $legoName, array $context): void
    {
        $policy = $this->forDomain($domain);
        $this->save($policy->withOverride($legoName, $context));
    }

    private function policyPath(string $domain): string
    {
        $safeDomain = str_replace(['/', '\\', '..'], '_', $domain);
        return $this->basePath . "/{$safeDomain}.json";
    }
}
```

### DomainPolicy

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Policy;

final readonly class DomainPolicy
{
    public function __construct(
        public string $domain,
        /** @var list<PolicyRule> */
        public array $rules,
        public int $totalActions,
        public int $totalOverrides,
        /** @var list<array{action: string, context: array, timestamp: string}> */
        public array $userActionLog,
    ) {}

    public static function empty(string $domain): self
    {
        return new self($domain, [], 0, 0, []);
    }

    public function withUserAction(array $action): self
    {
        $log = $this->userActionLog;
        $log[] = ['action' => $action['action'] ?? 'unknown', 'context' => $action, 'timestamp' => date('c')];

        if (count($log) > 500) {
            $log = array_slice($log, -500);
        }

        return new self($this->domain, $this->rules, $this->totalActions + 1, $this->totalOverrides, $log);
    }

    public function withOverride(string $legoName, array $context): self
    {
        return new self($this->domain, $this->rules, $this->totalActions, $this->totalOverrides + 1, $this->userActionLog);
    }

    public function withRules(array $rules): self
    {
        return new self($this->domain, $rules, $this->totalActions, $this->totalOverrides, $this->userActionLog);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            domain: $data['domain'],
            rules: array_map(static fn(array $r) => PolicyRule::fromArray($r), $data['rules'] ?? []),
            totalActions: $data['totalActions'] ?? 0,
            totalOverrides: $data['totalOverrides'] ?? 0,
            userActionLog: $data['userActionLog'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'rules' => array_map(static fn(PolicyRule $r) => $r->toArray(), $this->rules),
            'totalActions' => $this->totalActions,
            'totalOverrides' => $this->totalOverrides,
            'userActionLog' => $this->userActionLog,
        ];
    }
}
```

### PolicyRule

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Policy;

final readonly class PolicyRule
{
    public function __construct(
        public string $legoName,
        /** @var array<string, mixed> */
        public array $match,
        public float $confidence,
        public int $applied,
        public int $overridden,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            legoName: $data['legoName'],
            match: $data['match'] ?? [],
            confidence: (float) ($data['confidence'] ?? 0.0),
            applied: (int) ($data['applied'] ?? 0),
            overridden: (int) ($data['overridden'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'legoName' => $this->legoName,
            'match' => $this->match,
            'confidence' => $this->confidence,
            'applied' => $this->applied,
            'overridden' => $this->overridden,
        ];
    }
}
```

---

## ServiceBundle

```php
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
        $services->singleton(BridgeConfig::class)
            ->factory(static fn() => new BridgeConfig(
                dataDir: $context['BRIDGE_DATA_DIR'] ?? $_SERVER['HOME'] . '/.phalanx',
                port: (int) ($context['BRIDGE_PORT'] ?? 9078),
            ));

        $services->singleton(LegoLibrary::class)
            ->factory(static fn() => new LegoLibrary(
                basePath: ($context['BRIDGE_DATA_DIR'] ?? $_SERVER['HOME'] . '/.phalanx') . '/legos',
            ));

        $services->singleton(PolicyStore::class)
            ->factory(static fn() => new PolicyStore(
                basePath: ($context['BRIDGE_DATA_DIR'] ?? $_SERVER['HOME'] . '/.phalanx') . '/policies',
            ));

        $services->singleton(TabManager::class)
            ->lazy();
    }
}
```

### BridgeConfig

```php
<?php

declare(strict_types=1);

namespace AgentBridge;

final readonly class BridgeConfig
{
    public function __construct(
        public string $dataDir,
        public int $port = 9078,
    ) {}
}
```

---

## AI Integration

The bridge uses phalanx-athena's `AgentLoop` for three distinct agent roles. Each is an `AgentDefinition` with purpose-specific tools. The classifier runs frequently on cheap models. The generator runs rarely on capable models. The repair agent runs on failure.

### ClassifierAgent

Receives a batch of DOM data and the site's lego library. Returns a list of lego invocations.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use AgentBridge\Lego\LegoDefinition;
use Phalanx\Ai\AgentDefinition;
use Phalanx\Ai\Tool\Tool;
use Phalanx\ExecutionScope;

final class ClassifierAgent implements AgentDefinition
{
    public string $instructions {
        get => <<<'PROMPT'
            You are a DOM classifier. You receive a snapshot of DOM elements from a web page
            and a library of available action legos for this site. Your job is to classify
            which elements should have which lego applied to them.

            Respond by calling the classify_elements tool with your classifications.
            Only classify elements where you have high confidence the action matches
            the user's established patterns. When uncertain, skip the element.
            PROMPT;
    }

    public function __construct(
        /** @var list<LegoDefinition> */
        private readonly array $availableLegos,
        /** @var list<array<string, mixed>> */
        private readonly array $domElements,
        /** @var list<array<string, mixed>>|null */
        private readonly ?array $policyRules = null,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        return $scope->execute(
            \Phalanx\Ai\Agent::from($this)
                ->message($this->buildContext())
                ->maxSteps(3)
        );
    }

    /** @return list<class-string<Tool>> */
    public function tools(): array
    {
        return [ClassifyElements::class];
    }

    public function provider(): ?string
    {
        return 'fast';
    }

    private function buildContext(): string
    {
        $legos = json_encode(
            array_map(static fn(LegoDefinition $l) => [
                'name' => $l->name,
                'description' => $l->description,
                'confidence' => $l->confidence,
            ], $this->availableLegos),
            JSON_THROW_ON_ERROR,
        );

        $elements = json_encode($this->domElements, JSON_THROW_ON_ERROR);
        $context = "Available legos:\n{$legos}\n\nDOM elements:\n{$elements}";

        if ($this->policyRules !== null) {
            $context .= "\n\nUser policy rules:\n" . json_encode($this->policyRules, JSON_THROW_ON_ERROR);
        }

        return $context;
    }
}
```

### GeneratorAgent

Receives a DOM snapshot and user intent. Generates new `LegoDefinition` objects for unknown sites.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use Phalanx\Ai\AgentDefinition;
use Phalanx\Ai\Tool\Tool;
use Phalanx\ExecutionScope;

final class GeneratorAgent implements AgentDefinition
{
    public string $instructions {
        get => <<<'PROMPT'
            You are a browser automation expert. You receive a DOM snapshot from a web page
            and the user's stated intent (what they want to accomplish on this site).

            Analyze the DOM structure, identify interactive elements, and generate reusable
            action sequences (legos) that accomplish the user's intent.

            Each lego should be:
            - Named clearly (e.g., "archive_email", "create_ticket", "export_row")
            - Composed of primitive steps: click, type, fill, waitForSelector, etc.
            - Targeted at specific CSS selectors from the DOM
            - Robust: prefer data attributes and aria labels over fragile class names

            Use the create_legos tool to submit your generated legos.
            PROMPT;
    }

    public function __construct(
        private readonly string $domSnapshot,
        private readonly string $userIntent,
        private readonly string $domain,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        return $scope->execute(
            \Phalanx\Ai\Agent::from($this)
                ->message("Domain: {$this->domain}\n\nUser intent: {$this->userIntent}\n\nDOM snapshot:\n{$this->domSnapshot}")
                ->maxSteps(5)
        );
    }

    /** @return list<class-string<Tool>> */
    public function tools(): array
    {
        return [CreateLegos::class, ValidateSelector::class];
    }

    public function provider(): ?string
    {
        return null;
    }
}
```

### RepairAgent

Receives a broken lego and current DOM. Produces repaired steps with updated selectors.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use AgentBridge\Lego\LegoDefinition;
use Phalanx\Ai\AgentDefinition;
use Phalanx\Ai\Tool\Tool;
use Phalanx\ExecutionScope;

final class RepairAgent implements AgentDefinition
{
    public string $instructions {
        get => <<<'PROMPT'
            A browser automation lego has broken because the target website updated its UI.
            You receive the broken lego (with its original selectors) and a fresh DOM snapshot.

            Find the equivalent elements in the new DOM and update the selectors.
            Prefer stable selectors: data attributes, aria labels, role attributes, unique text content.
            Avoid class names that look auto-generated (hashed, obfuscated).

            Use the repair_lego tool to submit the repaired steps.
            PROMPT;
    }

    public function __construct(
        private readonly LegoDefinition $brokenLego,
        private readonly string $currentDom,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        return $scope->execute(
            \Phalanx\Ai\Agent::from($this)
                ->message("Broken lego:\n" . json_encode($this->brokenLego->toArray(), JSON_THROW_ON_ERROR) . "\n\nCurrent DOM:\n{$this->currentDom}")
                ->maxSteps(3)
        );
    }

    /** @return list<class-string<Tool>> */
    public function tools(): array
    {
        return [RepairLego::class];
    }

    public function provider(): ?string
    {
        return null;
    }
}
```

---

## Application Entry Point

The entry point uses direct `autoload.php` (not `symfony/runtime`). `Runner::run()` returns `int` (exit code), which is incompatible with `symfony/runtime`'s expectation of an object return from the closure.

```php
<?php

declare(strict_types=1);

use AgentBridge\BridgeConfig;
use AgentBridge\BridgeGateway;
use AgentBridge\BridgeServiceBundle;
use AgentBridge\Tab\TabManager;
use Phalanx\Application;
use Phalanx\Http\Runner;
use Phalanx\WebSocket\WsConfig;
use Phalanx\WebSocket\WsRoute;
use Phalanx\WebSocket\WsRouteGroup;
use React\EventLoop\Loop;

require_once __DIR__ . '/../vendor/autoload.php';

$app = Application::starting($_SERVER + $_ENV)
    ->providers(
        new BridgeServiceBundle(),
    )
    ->compile();

$config = $app->scope()->service(BridgeConfig::class);
$app->scope()->service(TabManager::class)->setApp($app);

// Lockfile write, stale detection, signal handlers (see daemon/SPEC.md Sections 1.2-1.3)
// ...

Runner::from($app, requestTimeout: 0.0)
    ->withWebsockets(WsRouteGroup::of([
        'WS /bridge' => new WsRoute(
            fn: new BridgeGateway(),
            config: new WsConfig(
                pingInterval: 15.0,
                maxMessageSize: 4 * 1024 * 1024,
            ),
        ),
    ]))
    ->run("0.0.0.0:{$config->port}");
```

### Native Messaging Bootstrap

The daemon writes a lockfile on startup. The Native Messaging host script reads it:

```bash
#!/bin/bash
# ~/.phalanx/native-messaging-host.sh
# Chrome Native Messaging bootstrap -- reads daemon port, responds with WS URL

read -r -n 4 length_bytes

PORT=$(python3 -c "import json; print(json.load(open('$HOME/.phalanx/daemon.lock'))['port'])" 2>/dev/null)

if [ -z "$PORT" ]; then
    RESPONSE='{"error":"daemon not running"}'
else
    RESPONSE="{\"wsUrl\":\"ws://localhost:${PORT}/bridge\"}"
fi

LEN=${#RESPONSE}
printf "$(printf '\\x%02x\\x%02x\\x%02x\\x%02x' $((LEN & 0xFF)) $(((LEN >> 8) & 0xFF)) $(((LEN >> 16) & 0xFF)) $(((LEN >> 24) & 0xFF)))$RESPONSE"
```

Native Messaging manifest (`~/.phalanx/com.phalanx.bridge.json`):

```json
{
    "name": "com.phalanx.bridge",
    "description": "Phalanx Agent Bridge daemon bootstrap",
    "path": "$HOME/.phalanx/native-messaging-host.sh",
    "type": "stdio",
    "allowed_origins": ["chrome-extension://EXTENSION_ID/"]
}
```

Symlinked to Chrome's manifest directory during installation:

```
~/Library/Application Support/Google/Chrome/NativeMessagingHosts/com.phalanx.bridge.json
  → ~/.phalanx/com.phalanx.bridge.json
```

---

## Implementation Notes

### Why WebSocket, Not Native Messaging, for Data

Native Messaging has a 1MB cap on messages from the host to Chrome. DOM snapshots and batched mutation events can approach this limit on complex pages. WebSocket has no practical limit.

Native Messaging is stdin/stdout -- inherently single-stream. WebSocket is multiplexed -- multiple tabs share one connection with interleaved messages.

Native Messaging's value is lifecycle management: `connectNative()` keeps the service worker alive indefinitely (Chrome 114+). A 20-line bootstrap script is cheaper than engineering around service worker sleep timeouts.

### Why File-Based Storage

LegoLibrary and PolicyStore use the filesystem.

- The data is small (dozens of JSON files per domain, each under 10KB)
- Human-readable and git-friendly
- No external service dependency
- No async I/O wrapper needed
- Users can inspect, edit, and back up manually

If storage volume grows to thousands of domains, migrate to SQLite.

### Scope Lifecycle as State Machine

TabScope has no `state` field or state machine enum. The scope's existence IS the state:

- Tab exists in `TabManager::$tabs` → connected
- Tab has fibers running → processing
- Tab's scope is disposed → disconnected, everything cleaned up

### Channel Buffer Sizing

TabScope inbound channel: **64 items**. DOM mutations and network events arrive in bursts. 64 gives the content script headroom to batch without backpressure stalling the WebSocket. If the channel fills, the Channel's hysteresis kicks in: producer suspends at capacity, resumes at 50%.

### Action ID Correlation

Same pending-map pattern as CdpSession's RPC correlation. TabScope correlates string IDs over a connection that may drop. If the WebSocket drops, `TabScope::dispose()` rejects all pending deferreds -- no action hangs forever.

### AI Cost Model

| Agent | Frequency | Model Tier | Token Volume | Cost |
|-------|-----------|------------|--------------|------|
| Classifier | Every DOM batch (~2-5s) | haiku / local | ~500-2000 tokens | Cheap |
| Generator | Once per new site | opus / sonnet | ~5000-20000 tokens | Expensive |
| Repair | On selector failure | sonnet | ~2000-5000 tokens | Medium |

The classifier can use a local model (Ollama) with zero API cost. Provider selection via `ProviderConfig` -- the `'fast'` key maps to whatever cheap option is configured.

---

## Testing Guide

### Unit Tests

- **BridgeMessage::fromJson** -- various message types, missing fields, malformed JSON
- **BridgeCommand** -- serialization round-trips for each static factory method
- **LegoDefinition** -- confidence calculation, immutable update methods, serialization
- **DomainPolicy** -- user action logging, override recording, rule management

### Integration Tests

- **BridgeGateway** -- WebSocket connection, message routing to TabManager
- **TabManager** -- tab connect/disconnect lifecycle, multi-session handling, session cleanup on disconnect
- **TabScope** -- action execution with mock WebSocket, pending action timeout, dispose cleans up
- **LegoExecutor** -- execute against mock tab, batch execution, failure handling
- **LegoLibrary** -- file I/O round-trips, domain isolation

### Agent Tests

- **ClassifierAgent** -- mock provider returns classification, verify lego invocation plan
- **GeneratorAgent** -- mock provider returns lego definitions, verify DOM analysis
- **RepairAgent** -- mock provider returns repaired selectors, verify lego update

### End-to-End Tests

- Full daemon startup → WebSocket connect → tab.connect → dom.snapshot → classifier → action.execute → action.result
- Tab disconnect during active lego execution → verify clean cancellation
- Multiple tabs connected simultaneously → verify independent scope lifecycle
