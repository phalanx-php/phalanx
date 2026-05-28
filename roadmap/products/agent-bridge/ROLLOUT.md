# Phalanx Agent Bridge -- Master Rollout Plan

Implementation plan for the Agent Bridge product. Two-part system: PHP async daemon (Phalanx framework) and Chrome extension (Plasmo/MV3). The extension streams live browser state to the daemon, which orchestrates AI-driven automation and sends action commands back.

Specs:
- `integration/SPEC.md` -- Wire protocol, failure modes, backpressure, build sequencing
- `daemon/SPEC.md` -- PHP daemon internals: process lifecycle, services, stream pipeline, AI tools
- `extension/SPEC.md` -- Chrome extension: Plasmo structure, content script, service worker, side panel
- `SPEC.md` -- Original product spec with PHP class designs
- Three `REVIEW.md` files with resolved issues

---

## Done When

The product is shippable when a user can:

1. Install the extension and start the daemon
2. Open the side panel, see their browser tabs, click "Connect" on any tab
3. Watch the confidence meters fill as the daemon observes and classifies page elements
4. Type a natural language instruction ("archive all newsletters", "export this table") and watch the agent generate and execute actions
5. See the agent learn from corrections -- override an action, watch confidence adjust, watch the agent adapt
6. Close Chrome, reopen it, and have the system reconnect automatically with all learned behaviors intact

Every phase in this plan builds toward that end state. Phase 2 proves step 2. Phase 3 proves observation. Phase 4 proves action execution. Phase 5 proves steps 3-4. Phase 6 proves step 6. Phase 7 polishes step 5.

---

## Strategy

### Progressive Integration

Build the simplest possible end-to-end flow first, then layer complexity. Every phase produces something visible and verifiable before moving to the next.

### Observability Is the Foundation

This system connects multiple processes (daemon + extension + browser + AI). You cannot write code and hope -- you must verify data is flowing at every stage. Two observation layers are available from day one:

1. **Daemon8** -- Provides CDP observation (console logs, network requests, DOM content), application telemetry ingestion (HTTP POST to port 9077), and MCP tools (`debug_observe`, `debug_act`, `debug_summary`). The extension's browser-side behavior is observable through Daemon8 without any additional setup. **Daemon8 is strictly optional** -- the bridge must never depend on it. All Daemon8 integration is fire-and-forget: if Daemon8 is offline, telemetry silently drops and the bridge runs identically. When available, it provides a unified timeline of daemon + browser events that makes debugging cross-system flows dramatically easier.

2. **Phalanx Trace** -- Built into the framework. Enabled via `PHALANX_TRACE=1` environment variable. Logs execution lifecycle events (`EXEC`, `DONE`, `FAIL`, `CON>`, `CON<`, `SVC+`, `SVC-`, `SUSP`, `CANC`) with timestamps, memory usage, and caller locations. The daemon should emit traces for every message received, every classification, and every action executed.

### Iterative Discovery

The plan supports the build-first-discover-patterns-rewrite-from-hindsight workflow. Phase 0 builds tooling. Phases 1-2 establish the wire. Phases 3-4 get data flowing. The architecture solidifies through use, not upfront design.

### Session Sizing

Each phase is completable in 1-3 focused coding sessions. No multi-week epics.

---

## Prerequisites

Before any implementation work:

| Prerequisite | Status | Action |
|---|---|---|
| Phalanx monorepo | Required | `phalanx/` must be at workspace root with `phalanx-aegis`, `phalanx-websocket`, `phalanx-styx`, `phalanx-athena` packages |
| Node.js 20+ | Required | For extension dev, mock servers, test tooling |
| pnpm | Required | Extension package manager |
| PHP 8.4+ | Required | Daemon runtime |
| Daemon8 running | Recommended | `daemon8 serve` -- provides browser observation via MCP tools. **Optional** -- the bridge runs without it. Telemetry POSTs silently fail if Daemon8 is offline. |
| Chrome | Required | Extension target browser |
| wscat or websocat | Recommended | Quick manual WebSocket testing: `npm install -g wscat` |

### Review Issues -- Resolved in Specs (Phase 0 Prerequisite)

The three REVIEW.md files identified critical issues. **These have already been corrected in the specs** during the review cycle. The table below is a record of what was fixed and which spec deliverable depends on the correction. Verify the fixes are present before starting any phase that references them.

| Issue | Fix Applied In | Unblocks |
|---|---|---|
| `user.chat` unregistered protocol message | integration/SPEC.md (Section 1.1), extension/SPEC.md (UserChat type), daemon/SPEC.md (Section 5.2) | Phase 5: handleUserChat, GeneratorAgent |
| `dom.response` routing bypasses stream pipeline | integration/SPEC.md (routing note), daemon/SPEC.md (Section 5.1 handleDomResponse) | Phase 4: queryDom, ValidateSelector, requestRepair |
| Cross-origin navigation missing `tab.disconnect` | extension/SPEC.md (port onDisconnect handler) | Phase 2: tab lifecycle integration |
| `__resend_snapshot` missing content script handler | extension/SPEC.md (Daemon Message Dispatch section) | Phase 6: reconnection |
| `attributeFilter` wildcards not supported | extension/SPEC.md (MutationObserver setup) | Phase 3: DOM observation |
| Port establishment + tracking | extension/SPEC.md (connect-tab.ts, Tab ID Initialization section) | Phase 2: content script activation |
| `alarms` permission missing from manifest | extension/SPEC.md (permissions manifest) | Phase 1: extension scaffold |
| Plasmo port handler conflict | extension/SPEC.md (removed tab-bridge.ts, directory layout) | Phase 2: content script ports |
| `evaluate` op routes through SW for MAIN world execution | extension/SPEC.md (`__evaluate` protocol, `evaluateViaServiceWorker`), integration/SPEC.md (op table) | Phase 4: action executor |
| Dead throttle/resume config values | daemon/SPEC.md (replaced with maxEventsPerSecThrottled) | Phase 6: flow control |
| `flow.throttle maxEventsPerSec=0` destroys observer | extension/SPEC.md (throttle response section) | Phase 6: flow control |
| `waitForNetworkViaServiceWorker` undefined | extension/SPEC.md (cross-context protocol section) | Phase 4: waitForNetwork op |
| Reconnection sends stale metadata | extension/SPEC.md (handleWsOpen uses chrome.tabs.get() return values) | Phase 6: reconnection |
| `net.response` missing `url` field | integration/SPEC.md (Section 1.1), extension/SPEC.md (NetResponse type, network-observer.ts) | Phase 3: network event classification |
| Tab ID collision across sessions | integration/SPEC.md (Section 1.1 tab.connect sessionId), extension/SPEC.md (TabConnect type) | Phase 6: multi-session |
| Port connection pattern contradiction | extension/SPEC.md (Port Communication, handlePortConnect -- SW initiates) | Phase 2: content script activation |
| Agent actions recorded as user actions | extension/SPEC.md (actionInProgress guard in executeAction + user action listeners) | Phase 3: user action observation, Phase 5: policy learning |
| `flow.resume` missing snapshot resend | extension/SPEC.md (handleFlowResume sends snapshot after re-enabling observer) | Phase 6: flow control |
| `cancelledActions` Set memory leak | extension/SPEC.md (cleanup in executeAction finally block) | Phase 4: action cancellation |
| `chrome.storage.session` clearing behavior | integration/SPEC.md (Section 2.8), extension/SPEC.md (handleInstalled always restores) | Phase 6: reconnection |
| Content script module split (stealth readiness) | extension/SPEC.md (directory layout, Section 3 Module Split) | Phase 2: content script scaffold, future stealth mode |

---

## Phase 0: Test Infrastructure and Observability -- COMPLETE

**Goal:** Before writing any product code, build the tools that let you see what is happening at every stage. These tools are used throughout ALL subsequent phases -- they are the integration test infrastructure.

**Status:** All deliverables built and verified. Protocol logger, mock daemon (passive + scripted + interactive + drop simulation), mock extension (single message + scenario + multi-client), 5 scenario files, Daemon8 trace listener (PHP class, pending composer setup in Phase 1).

**Agent:** `bridge-integration-architect` (protocol tooling), `daemon8-runtime-engineer` (observability)

**Duration:** 1-2 sessions

### 0.1 Protocol Message Logger

A standalone Node.js script that sits between any WebSocket client and server, logging every message with timestamps and direction.

**Deliverables:**
- `tools/protocol-logger.ts` -- WebSocket proxy that logs `[timestamp] [direction] [type] [payload-summary]`
- Runs as `npx tsx tools/protocol-logger.ts --listen 9079 --forward ws://localhost:9078/bridge`
- Outputs NDJSON to stdout (pipeable to `jq` for filtering)
- Colorized terminal output: green for extension-to-daemon, blue for daemon-to-extension

**Verification:**
```bash
# Terminal 1: Start the logger proxy
npx tsx tools/protocol-logger.ts --listen 9079 --forward ws://localhost:9078/bridge

# Terminal 2: Connect wscat through the proxy
wscat -c ws://localhost:9079
> {"type":"tab.connect","tabId":1,"url":"https://example.com","title":"Test","domain":"example.com"}

# Terminal 1 should show:
# [14:30:00.123] -> tab.connect  tabId=1 domain=example.com
```

### 0.2 Mock WebSocket Server (for extension testing)

A Node.js WebSocket server that the extension connects to instead of the real daemon. Supports scripted responses and connection simulation.

**Deliverables:**
- `tools/mock-daemon.ts` -- Accepts connections on configurable port, logs all received messages
- Scripted response mode: `--script responses.json` sends predetermined responses on matching messages
- Interactive mode: reads JSON from stdin, sends to connected client
- Simulates disconnection: `--drop-after 5s` closes connection after delay
- Supports clean close (code 1000) and dirty close (TCP reset)

**Verification:**
```bash
# Start mock daemon
npx tsx tools/mock-daemon.ts --port 9078

# Load extension, open side panel, connect a tab
# Mock daemon terminal shows:
# [connected] client 1
# [recv] {"type":"tab.connect","tabId":42,"url":"...","title":"...","domain":"..."}
# [recv] {"type":"dom.snapshot","tabId":42,"html":"...","selector":"body","timestamp":...}
```

### 0.3 Mock WebSocket Client (for daemon testing)

A scriptable WebSocket client that sends protocol messages to the daemon and asserts on responses.

**Deliverables:**
- `tools/mock-extension.ts` -- Connects to daemon, sends messages from script or stdin
- Script mode: `--script scenario.json` sends a sequence of messages with timing
- Assertion mode: `--expect 'ui.update' --within 2s` fails if expected message not received
- Multi-client mode: `--clients 3` opens N connections for multi-session testing
- Records all received messages as NDJSON for post-hoc analysis

**Verification:**
```bash
# Terminal 1: Start daemon
PHALANX_TRACE=1 php bin/bridge

# Terminal 2: Run mock extension
npx tsx tools/mock-extension.ts --url ws://localhost:9078/bridge --script scenarios/tab-connect.json

# Scenario file:
# [
#   {"delay": 0, "send": {"type":"tab.connect","tabId":1,"url":"https://example.com","title":"Test","domain":"example.com"}},
#   {"delay": 100, "expect": {"type":"ui.update","target":"status"}, "within": 2000}
# ]
```

### 0.4 Daemon Telemetry to Daemon8

Configure the Phalanx daemon to send trace events to Daemon8's HTTP ingestion endpoint. This gives you a unified observation stream: daemon traces alongside browser CDP events in the same Daemon8 timeline.

**Critical constraint: Daemon8 is optional. The bridge must never depend on Daemon8 being online.** The telemetry layer is fire-and-forget -- if Daemon8 is unreachable, the POST silently fails and the daemon continues running without interruption. No retries, no queuing, no error propagation. The daemon's core functionality (WebSocket server, stream pipeline, AI classification, action execution) must work identically whether Daemon8 is running or not.

**Deliverables:**
- A `Daemon8TraceListener` (or equivalent hook) that POSTs trace entries to `http://localhost:9077/ingest` as they occur
- Each trace entry becomes a Daemon8 observation with `app: "agent-bridge"`, `kind: "log"`, and structured data containing the trace type, subject, and timing
- Wire message logging: every inbound/outbound WebSocket message logged as a Daemon8 observation with `kind: "custom"`, `channel: "wire"`
- **The listener is only instantiated when `DAEMON8_INGEST` is set.** If the env var is absent, no listener is created, no HTTP client is allocated, zero overhead.
- **All POSTs are non-blocking fire-and-forget.** Use ReactPHP's async HTTP client with no await on the response. Catch and discard all exceptions (connection refused, timeout, DNS failure). A failed POST must never surface as an error in the daemon's logs or interrupt any fiber.
- **No health checks, no reconnection logic, no circuit breaker.** The telemetry path is a one-way data sink. If Daemon8 comes online mid-session, the next POST succeeds automatically. If it goes offline, POSTs fail silently. The daemon does not track or care about Daemon8's state.

**Implementation pattern:**

```php
<?php

// Only created when DAEMON8_INGEST is configured
if (isset($context['DAEMON8_INGEST'])) {
    $services->singleton(Daemon8TraceListener::class)
        ->factory(static fn() => new Daemon8TraceListener(
            endpoint: $context['DAEMON8_INGEST'],
        ));
}
```

```php
<?php

final class Daemon8TraceListener
{
    private readonly \React\Http\Browser $http;

    public function __construct(
        private readonly string $endpoint,
    ) {
        // No timeout, no retries -- fire and forget
        $this->http = new \React\Http\Browser();
    }

    public function emit(string $kind, string $channel, array $data): void
    {
        $payload = json_encode([
            'app' => 'agent-bridge',
            'kind' => $kind,
            'channel' => $channel,
            'data' => $data,
            'ts' => hrtime(true),
        ], JSON_THROW_ON_ERROR);

        // Fire and forget. No await. Discard result and errors.
        $this->http->post($this->endpoint, ['Content-Type' => 'application/json'], $payload)
            ->then(null, static fn() => null);
    }
}
```

**Verification:**
```bash
# With Daemon8 running:
PHALANX_TRACE=1 DAEMON8_INGEST=http://localhost:9077/ingest php bin/bridge
# Query Daemon8: debug_observe with origins=["app:agent-bridge"]
# Should show: wire tab.connect received, EXEC handleTabMessage, etc.

# Without Daemon8 (or with it stopped):
PHALANX_TRACE=1 DAEMON8_INGEST=http://localhost:9077/ingest php bin/bridge
# Daemon starts normally. No errors. Wire protocol works identically.
# Phalanx trace still prints to stdout via PHALANX_TRACE=1.

# Without the env var at all:
PHALANX_TRACE=1 php bin/bridge
# No Daemon8 integration. No HTTP client created. Zero overhead.
```

### 0.5 Test Scenarios Library

Pre-built JSON scenario files for common protocol exchanges.

**Deliverables:**
- `tools/scenarios/tab-lifecycle.json` -- connect, navigate, disconnect
- `tools/scenarios/dom-streaming.json` -- connect, snapshot, mutations, mutations, mutations
- `tools/scenarios/action-roundtrip.json` -- connect, snapshot, then expect action.execute, send action.result
- `tools/scenarios/reconnection.json` -- connect, drop, reconnect, re-send tab.connect + snapshot
- `tools/scenarios/flow-control.json` -- rapid-fire mutations to trigger backpressure

**Test coverage added in this phase:**
- None (this is tooling, not product code)

---

## Phase 1: Wire Protocol -- Both Sides Can Speak -- COMPLETE

**Goal:** The daemon accepts a WebSocket connection and correctly parses/routes every protocol message type. The extension connects to a WebSocket server and correctly sends/receives every message type. Neither side needs the other -- tested with mock counterparts.

**Status:** All daemon wire types and connection layer built and verified. BridgeMessage, BridgeCommand, BridgeGateway, ExtensionSession, BridgeConfig, BridgeServiceBundle implemented and passing tests.

**Implementation notes:**
- Entry point uses direct `autoload.php` instead of `symfony/runtime` -- `Runner::run()` returns `int`, incompatible with symfony/runtime's expected object return.
- `BridgeGateway::__invoke` signature is `__invoke(Scope $scope): mixed` (not `WsScope $scope: void`) because `Scopeable::__invoke(Scope $scope): mixed` is the interface contract. Runtime assert for `WsScope`.
- `BridgeMessage` uses `public private(set)` properties with `?string $domain` as a promoted constructor parameter (not a property hook). `fromJson()` reads wire `domain` first, falls back to `parse_url($url, PHP_URL_HOST)`.
- JSON decode depth is 2048 (not 512) to accommodate deeply nested DOM snapshots.
- Pump loop wraps decode in try/catch and continues on malformed frames (no crash on bad input).
- Gateway routing includes `dom.response` exact match before `dom.*` prefix match, and `user.*` routes to `handleUserMessage` (not `handleUserAction`) to support both `user.action` and `user.chat`.
- Tests use the Phalanx testing framework (`Phalanx\Testing\*` classes: TestScope, ScopedTestApp, probes, stubs).

**Dependencies:** Phase 0 tools

**Duration:** 2-3 sessions

### 1.1 Daemon Wire Types (D1)

**Agent:** `async-php84-phalanx-native-expert`

Build the data layer. No I/O, no event loop. Pure data transformation.

**Deliverables:**
- `daemon/src/BridgeMessage.php` -- `fromJson(string): self` parses all extension-to-daemon message types
- `daemon/src/BridgeCommand.php` -- Static factories for every daemon-to-extension message, each with `toJson(): string`
- Unit tests: round-trip every message type through serialize/deserialize

| Class | Methods | Test Count |
|---|---|---|
| `BridgeMessage` | `fromJson()`, type/tabId/payload accessors | ~15 (one per message type + malformed + unknown) |
| `BridgeCommand` | `executeAction()`, `cancelAction()`, `domRequest()`, `uiUpdate()`, `throttle()`, `resume()` | ~8 (one per factory + round-trip) |

**Verification:**
```bash
composer test -- --filter BridgeMessage
# All 15 tests pass

composer test -- --filter BridgeCommand
# All 8 tests pass
```

### 1.2 Daemon Connection Layer (D2)

**Agent:** `async-php84-phalanx-native-expert`

Accept WebSocket connections, decode JSON frames, route by type prefix.

**Deliverables:**
- `daemon/src/BridgeGateway.php` -- Invokable WsRoute handler. Pump loop reads from `WsConnection`, decodes JSON into `BridgeMessage`, routes by type prefix
- `daemon/src/ExtensionSession.php` -- Wraps WsConnection, provides `send(BridgeCommand)` method
- `daemon/bin/bridge` -- Entry point with direct `autoload.php` (no symfony/runtime), `BridgeServiceBundle`, `TabManager::setApp()`, single WS route
- Lockfile write/read (`~/.phalanx/daemon.lock`)
- Signal handling (SIGTERM, SIGINT)

**Verification:**
```bash
# Terminal 1: Start daemon
PHALANX_TRACE=1 php daemon/bin/bridge

# Terminal 2: Connect with wscat
wscat -c ws://localhost:9078/bridge
> {"type":"tab.connect","tabId":1,"url":"https://example.com","title":"Test","domain":"example.com"}

# Terminal 1 trace output should show:
#   0ms  EXEC  BridgeGateway
#   1ms  EXEC  handleTabMessage  tab.connect tabId=1

# Terminal 2: Send unknown type
> {"type":"foo.bar","tabId":1}

# Terminal 1: Should log unknown type, no crash
```

```bash
# Verify lockfile
cat ~/.phalanx/daemon.lock
# {"port":9078,"pid":12345,"started":"2026-04-04T14:30:00-05:00"}

# Verify clean shutdown
kill $(jq -r .pid ~/.phalanx/daemon.lock)
cat ~/.phalanx/daemon.lock
# File should not exist
```

### 1.3 Extension Wire Types

**Agent:** `plasmo-extension-expert`

TypeScript types mirroring the integration spec. Build system setup.

**Deliverables:**
- `extension/` -- Plasmo project scaffold: `package.json`, `tsconfig.json`, `.env`
- `extension/src/lib/types.ts` -- All TypeScript interfaces from integration spec Section 2
- `extension/src/lib/constants.ts` -- Buffer sizes, timeouts, backoff config
- Unit tests: type serialization/deserialization

**Verification:**
```bash
cd extension && pnpm install && pnpm test
# Type tests pass
```

### 1.4 Extension Service Worker Scaffold (E1)

**Agent:** `plasmo-extension-expert`

WebSocket connection to mock server. Message send/receive. State persistence.

**Deliverables:**
- `extension/src/background/index.ts` -- Service worker entry with synchronous listener registration
- `extension/src/lib/ws-manager.ts` -- WebSocket lifecycle, reconnection with backoff
- `extension/src/lib/state.ts` -- `chrome.storage.session` persistence, `loadState`/`persistState`
- `extension/src/lib/native-bootstrap.ts` -- `connectNative()` port discovery (with hardcoded fallback for dev)
- Manifest permissions: `sidePanel`, `storage`, `tabs`, `activeTab`, `scripting`, `webRequest`, `webNavigation`, `nativeMessaging`, `alarms`

**Verification:**
```bash
# Terminal 1: Start mock daemon
npx tsx tools/mock-daemon.ts --port 9078

# Load extension at chrome://extensions (Developer mode, load unpacked build/chrome-mv3-dev)
# Open DevTools for service worker (click "Inspect views: service worker")

# Mock daemon terminal shows:
# [connected] client 1

# Kill mock daemon (Ctrl+C), wait, restart it
# Service worker DevTools console shows reconnection attempts
# Mock daemon shows new connection after backoff
```

Observe via Daemon8:
```
debug_observe(kinds=["log"], origins=["browser"], text_match="WebSocket")
```
This shows Chrome's console logs from the service worker, including connection state changes.

**Test coverage added in this phase:**

| Component | Test Type | Description |
|---|---|---|
| `BridgeMessage` | Unit (PHPUnit) | All message types, malformed input, unknown types |
| `BridgeCommand` | Unit (PHPUnit) | All factories, JSON round-trip |
| `BridgeGateway` | Integration (PHPUnit + ThroughStream) | Message routing, unknown type handling |
| TypeScript types | Unit (Vitest) | Serialization/deserialization |
| `ws-manager` | Unit (Vitest) | Reconnection state machine, backoff timing |
| `state.ts` | Unit (Vitest) | `loadState`/`persistState` round-trip |

---

## Phase 2: Tab Lifecycle -- Plumbing Works End-to-End -- COMPLETE

**Goal:** Connect a real Chrome tab through the extension to the daemon. Verify the daemon creates a TabScope, the extension tracks state, and disconnect cleans up on both sides. This is the first moment where both halves of the system run together.

**Status:** Daemon tab management fully implemented and verified. TabManager, TabScope, and all message routing working. TabManager uses `setApp()` pattern for post-compile AppHost injection (AppHost is not available during service container registration).

**Implementation notes:**
- `TabManager` constructor receives only `LegoLibrary` and `PolicyStore` (auto-injected singletons). `AppHost` is provided via `setApp()` called from `bin/bridge` after `compile()`.
- `TabScope::$domain` is `?string` (nullable) since domain may not be parseable from all URLs.
- `TabScope::$pendingActions` is `public` so `TabManager::handleDomResponse()` can resolve `dom.response` Deferreds by `requestId` without routing through TabScope methods.
- `TabManager::handleDomResponse()` is a separate method (not routed through `handleDomMessage`) -- dom.response is request-reply, not a stream event.
- `TabManager::handleUserMessage()` dispatches both `user.action` and `user.chat` (Phase 5 stub).
- Tests use the Phalanx testing framework (`Phalanx\Testing\*`).

**Dependencies:** Phase 1 (daemon connection layer, extension service worker)

**Duration:** 2-3 sessions

### 2.1 Daemon Tab Management (D3)

**Agent:** `async-php84-phalanx-native-expert`

**Deliverables:**
- `daemon/src/Tab/TabManager.php` -- Creates/destroys `TabScope` on connect/disconnect, session cleanup, `setApp()` for post-compile AppHost injection, `handleDomResponse()` for request-reply routing, `handleUserMessage()` for `user.action`/`user.chat` dispatch
- `daemon/src/Tab/TabScope.php` -- Per-tab scope with inbound Channel (bufferSize: 64), public `pendingActions` map, cancellation token, `executeAction()`, `queryDom()`, `say()`, `dispose()`
- `daemon/src/BridgeServiceBundle.php` -- Registers `BridgeConfig`, `TabManager` (with auto-injected `LegoLibrary`/`PolicyStore`), `LegoLibrary`, `PolicyStore`
- `daemon/src/BridgeConfig.php` -- All config values from `$context`
- `daemon/src/ExtensionSession.php` -- WsConnection wrapper with `send(BridgeCommand)`, tab ownership tracking
- `ui.update` status response on `tab.connect` (acknowledges connection to side panel)

**Verification:**
```bash
# Start daemon with trace
PHALANX_TRACE=1 php daemon/bin/bridge

# Run tab lifecycle scenario
npx tsx tools/mock-extension.ts --url ws://localhost:9078/bridge --script scenarios/tab-lifecycle.json

# Expected trace output:
# SVC+ TabManager
# EXEC tab.connect tabId=1 domain=example.com
# SVC+ TabScope[1]
# EXEC tab.navigate tabId=1 url=https://example.com/page2
# EXEC tab.disconnect tabId=1
# SVC- TabScope[1]

# Verify Daemon8 shows the same events
# debug_observe(origins=["app:agent-bridge"], text_match="tab")
```

Verify scope disposal:
```bash
# Connect a tab, then close the WebSocket (Ctrl+C wscat)
# Daemon trace should show:
# SVC- TabScope[1]  (disposed when session ends)
# No orphaned timers, no pending deferreds
```

### 2.2 Extension Content Script (E3 -- partial)

**Agent:** `plasmo-extension-expert`

Build enough of the content script to send `tab.connect` and `dom.snapshot`. Action execution and mutation observation come in later phases.

**Deliverables:**
- `extension/src/contents/bridge.ts` -- Content script with Plasmo config, port listener, tab ID initialization
- Port communication: content script initiates port via `chrome.runtime.connect()`, service worker detects via `onConnect`
- `sendTabConnect()` -- Sends `tab.connect` with current tab URL, title, domain
- `sendInitialSnapshot()` -- Sends `dom.snapshot` of `document.body.innerHTML` (scoped, not full page)
- `extension/src/background/messages/connect-tab.ts` -- Side panel requests tab connection
- `extension/src/background/messages/disconnect-tab.ts` -- Side panel requests tab disconnection

**Verification:**
```bash
# Terminal 1: Start daemon with trace
PHALANX_TRACE=1 php daemon/bin/bridge

# Load extension, navigate to https://example.com
# Open side panel (click extension icon)
# Click "Connect" on the example.com tab

# Daemon trace shows:
# EXEC tab.connect tabId=42 domain=example.com
# SVC+ TabScope[42]

# Protocol logger (if running) shows:
# -> tab.connect  tabId=42 domain=example.com
# -> dom.snapshot tabId=42 selector=body timestamp=...
# <- ui.update    target=status data={tabId:42, state:"connected", domain:"example.com"}
```

Observe via Daemon8:
```
debug_observe(origins=["browser"], text_match="tab-bridge")
```
Shows Chrome's console output from the content script and service worker as port connections are established.

### 2.3 Extension Side Panel (E4 -- minimal)

**Agent:** `plasmo-extension-expert`

Minimal side panel: connection status and tab list. Enough to trigger tab connect/disconnect.

**Deliverables:**
- `extension/src/sidepanel.tsx` -- Root component with side panel port
- `extension/src/components/StatusBar.tsx` -- Daemon online/offline/reconnecting
- `extension/src/components/TabConnector.tsx` -- List of tabs with connect/disconnect buttons
- `extension/src/components/TabBadge.tsx` -- Per-tab status indicator

**Verification:**

Manual: Open side panel, see list of open tabs, click Connect on one, see status change to "Connected". Click Disconnect, see status revert.

### 2.4 Milestone: Single Tab Lifecycle

The first end-to-end test. Extension connects to real daemon. Tab connect creates TabScope. Tab disconnect disposes TabScope. Close WebSocket disposes all tabs.

**Verification checklist:**

| Step | Action | Daemon Trace Expected | Extension Console Expected |
|---|---|---|---|
| 1 | Open side panel, click Connect on a tab | `EXEC tab.connect`, `SVC+ TabScope[N]` | Port connected, tab.connect sent |
| 2 | Navigate the tab to a new URL (same origin) | `EXEC tab.navigate` | tab.navigate sent |
| 3 | Navigate the tab cross-origin | `EXEC tab.disconnect`, `SVC- TabScope[N]`, `EXEC tab.connect`, `SVC+ TabScope[N]` | tab.disconnect + tab.connect sent |
| 4 | Click Disconnect in side panel | `EXEC tab.disconnect`, `SVC- TabScope[N]` | Port disconnected |
| 5 | Close the tab while connected | `EXEC tab.disconnect`, `SVC- TabScope[N]` | Tab removed handler fired |
| 6 | Kill daemon while tabs connected | -- | WebSocket onclose, reconnecting... |
| 7 | Restart daemon | `SVC+ TabManager` | Reconnected, tab.connect resent |

Observe the entire sequence in Daemon8:
```
debug_checkpoint()  # Mark start
# ... run through the checklist ...
debug_observe(since_checkpoint=<id>, origins=["app:agent-bridge"])
# See every trace event from the daemon in chronological order
```

**Test coverage added in this phase:**

| Component | Test Type | Description |
|---|---|---|
| `TabManager` | Integration (PHPUnit + mock WS) | Connect/disconnect sequences, multi-session, session cleanup |
| `TabScope` | Unit (PHPUnit) | Disposal cancels tokens, rejects deferreds, completes channels |
| Content script port init | Manual + Daemon8 | Port establishment, tab ID handshake |
| Side panel tab connector | Manual | Connect/disconnect buttons, status display |

---

## Phase 3: DOM Streaming -- Data Flows

**Goal:** DOM mutations stream from the browser through the daemon's stream pipeline. Observable at every stage: content script batching, WebSocket transmission, Channel ingestion, stream operator output.

**Dependencies:** Phase 2 (tab lifecycle working end-to-end)

**Duration:** 2-3 sessions

### 3.1 Content Script DOM Observation (E3 -- full)

**Agent:** `plasmo-extension-expert`

This is the largest single deliverable. Build in order: MutationObserver first (verify mutations flow), then user actions (verify click/type observation), then buffer management (verify pressure reporting), then network observation (verify net.request/response). Each sub-deliverable is independently verifiable via the protocol logger before moving to the next.

**Deliverables:**
- MutationObserver setup with `requestAnimationFrame` batching (no `attributeFilter` -- observe all, filter in summarizer)
- Mutation summarizer: `MutationRecord[]` to `MutationSummary[]` with visibility filter, deduplication, selector generation
- CSS selector generator with priority cascade: `data-testid` > `#id` > `aria-label` > `tag.class` > `nth-child`
- User action observers: click, type, select, scroll, submit
- Buffer management: 64-message outbound buffer, drop-oldest on overflow, `flow.pressure` emission at threshold 16
- Network observation: `chrome.webRequest.onBeforeRequest` / `onCompleted` forwarding `net.request` / `net.response`

**Verification:**
```bash
# Start daemon with trace and protocol logger
PHALANX_TRACE=1 php daemon/bin/bridge
npx tsx tools/protocol-logger.ts --listen 9079 --forward ws://localhost:9078/bridge

# Connect a tab to a dynamic page (e.g., Gmail, GitHub issues list)
# Interact with the page: click elements, type in inputs, scroll

# Protocol logger shows stream of messages:
# -> dom.mutations  tabId=42 mutations=[{type:childList, target:..., addedCount:3}]
# -> user.action    tabId=42 action=click target=[data-testid="issue-42"]
# -> net.request    tabId=42 method=GET url=https://api.github.com/...
# -> net.response   tabId=42 status=200 contentType=application/json

# Daemon trace shows messages being received:
# EXEC handleDomMessage  dom.mutations tabId=42
# EXEC handleNetMessage  net.response tabId=42
# EXEC handleUserMessage user.action tabId=42
```

Observe mutations in Daemon8:
```
debug_observe(origins=["app:agent-bridge"], text_match="dom.mutations")
# Shows each batch of mutations as received by the daemon
```

### 3.2 Daemon Stream Pipeline (D4)

**Agent:** `async-php84-phalanx-native-expert`

**Deliverables:**
- `TabScope::startPipeline()` -- Wires inbound Channel through stream operators
- Pipeline: `filter(dom.snapshot|dom.mutations|net.response)` -> `throttle(0.5)` -> `bufferWindow(20, 2.0)` -> `onEach(classifyBatch)`
- Classify batch is a no-op stub for now -- logs the batch contents and size
- Backpressure: Channel `withPressure` callback sends `flow.throttle` / `flow.resume`

**Verification:**
```bash
# With a connected tab on a dynamic page:

# Daemon trace shows pipeline activity:
# EXEC TabScope::startPipeline tabId=42
# EXEC bufferWindow emitted  batch_size=8

# The classify stub logs:
# [classify] tabId=42 batch: 3 snapshots, 4 mutations, 1 network

# Verify throttle/resume with rapid mutations:
npx tsx tools/mock-extension.ts --url ws://localhost:9078/bridge \
  --script scenarios/flow-control.json

# Daemon trace shows:
# EXEC flow.throttle  tabId=1 maxEventsPerSec=5
# ... (buffer drains) ...
# EXEC flow.resume    tabId=1
```

### 3.3 Milestone: DOM Streaming End-to-End

Connect a real tab. Observe mutations flowing through the pipeline.

**Verification checklist:**

| Observation Point | What You See | How to Observe |
|---|---|---|
| Content script | Mutations batched per rAF, selectors generated | `debug_observe(origins=["browser"], text_match="mutation")` |
| Protocol logger | `dom.mutations` messages with summarized mutation arrays | Protocol logger stdout |
| Daemon inbound Channel | Messages arriving, Channel buffer depth | Daemon trace: `EXEC handleDomMessage` |
| Stream operators | Filter passes dom/net, throttle limits rate | Daemon trace: `bufferWindow emitted` |
| Classify stub | Batch contents logged | Daemon stdout / Daemon8 observation |

**Test coverage added in this phase:**

| Component | Test Type | Description |
|---|---|---|
| Mutation summarizer | Unit (Vitest) | Given mock MutationRecords, verify output format |
| CSS selector generator | Unit (Vitest) | Priority cascade, stability assessment |
| Buffer management | Unit (Vitest) | Drop-oldest at capacity, flush on reconnect |
| Stream pipeline | Integration (PHPUnit) | Send message sequence via mock WS, verify operator chain output |
| Backpressure | Integration (PHPUnit) | Fill Channel, verify throttle sent; drain, verify resume |

---

## Phase 4: Actions -- Daemon Commands the Browser

**Goal:** The daemon sends action commands to the content script, the content script executes DOM operations, and results flow back. The full round-trip is observable.

**Dependencies:** Phase 3 (DOM streaming working)

**Duration:** 2-3 sessions

### 4.1 Content Script Action Executor (E3 -- actions)

**Agent:** `plasmo-extension-expert`

**Deliverables:**
- Action step executor: all 16 ops from integration spec Section 1.3
- `executeAction()` -- Sequential step execution with cancellation support
- `sendResult()` -- Sends `action.result` with success/failure/data
- `handleDomRequest()` -- Responds to `dom.request` with `dom.response`
- `waitForNetworkViaServiceWorker()` -- Cross-context network wait (internal `__waitForNetwork` / `__networkComplete` protocol)
- `evaluateViaServiceWorker()` -- Cross-context MAIN world evaluation (internal `__evaluate` / `__evaluateResult` protocol). The `evaluate` op routes through the service worker via `chrome.scripting.executeScript({ world: 'MAIN' })` for page-context JS access.
- Service worker `handleEvaluate()` -- Receives `__evaluate` from content script, calls `chrome.scripting.executeScript`, returns `__evaluateResult`
- `mainWorld` flag dispatch: `fill` and `type` ops with `mainWorld: true` route through the same SW `executeScript` path. Schema-ready in `ActionStep` type, but implementation deferred until empirically needed (triggered by RepairAgent diagnosing controlled-input failures)

**Verification:**
```bash
# Connect a tab to a test page (create a simple HTML page with known elements)
# From mock extension or daemon, send an action:

npx tsx tools/mock-extension.ts --url ws://localhost:9078/bridge \
  -m '{"type":"tab.connect","tabId":1,"url":"http://localhost:3000/test","title":"Test","domain":"localhost"}' \
  --wait 1s \
  --listen-for action.execute

# Or: manually trigger from the daemon side by modifying the classify stub
# to send a test action.execute

# Protocol logger shows:
# <- action.execute  tabId=1 actionId=act_1 steps=[{op:click, selector:#button}]
# -> action.result   tabId=1 actionId=act_1 success=true
```

Test each op individually:
```bash
# Start mock daemon in interactive mode
npx tsx tools/mock-daemon.ts --port 9078 --interactive

# After extension connects, send:
{"type":"action.execute","tabId":42,"actionId":"test_1","steps":[{"op":"click","selector":"#my-button"}]}

# Content script executes click, returns:
# {"type":"action.result","tabId":42,"actionId":"test_1","success":true}

# Test DOM query:
{"type":"dom.request","tabId":42,"requestId":"dreq_1","selector":"h1","attrs":["textContent"]}

# Returns:
# {"type":"dom.response","tabId":42,"requestId":"dreq_1","elements":[{"textContent":"Hello World"}]}
```

### 4.2 Daemon Action Correlation (D5)

**Agent:** `async-php84-phalanx-native-expert`

**Spec prerequisite:** `dom.response` routing fix (daemon/SPEC.md Section 5.1). Verify the gateway routes `dom.response` to `handleDomResponse` by exact match BEFORE the `dom.*` prefix match. Without this, `queryDom()` hangs forever.

**Deliverables:**
- `TabScope::executeAction(steps)` -- Sends `action.execute`, creates Deferred, awaits `action.result`, with timeout
- `TabScope::queryDom(selector, attrs?, limit?)` -- Sends `dom.request`, creates Deferred, awaits `dom.response`
- `TabManager::handleActionResult()` -- Routes `action.result` to pending Deferred by `actionId`
- `TabManager::handleDomResponse()` -- Routes `dom.response` to pending Deferred by `requestId` (not through stream pipeline)
- `daemon/src/Lego/LegoDefinition.php` -- Immutable lego definition with steps, domain, confidence tracking
- `daemon/src/Lego/LegoExecutor.php` -- Executes a LegoDefinition against a TabScope
- `daemon/src/Lego/LegoLibrary.php` -- File-based storage at `~/.phalanx/legos/{domain}/`

**Verification:**
```bash
# Test action round-trip with mock extension
npx tsx tools/mock-extension.ts --url ws://localhost:9078/bridge \
  --script scenarios/action-roundtrip.json

# Daemon trace shows:
# EXEC executeAction  actionId=act_1 steps=2
# ... (waits for result) ...
# DONE executeAction  actionId=act_1 success=true +450ms

# Test timeout:
# Send action.execute but do NOT respond with action.result
# After 30s (configurable), daemon trace shows:
# FAIL executeAction  actionId=act_2 TimeoutException +30.0s
# <- action.cancel   tabId=1 actionId=act_2
```

### 4.3 Milestone: Action Round-Trip

Full loop: daemon sends action.execute to a real connected tab, content script executes DOM operations on a real page, result flows back.

**Verification:**

Create a test page at `agent-bridge/tools/test-page.html`:
```html
<button id="clicker" onclick="this.textContent='Clicked!'">Click me</button>
<input id="typer" />
<div id="result"></div>
```

Serve it with any static server and connect it as a tab. Then trigger actions from the daemon (either via the classify stub or a test script).

| Action | Steps | Expected Result |
|---|---|---|
| Click | `[{op:"click", selector:"#clicker"}]` | Button text changes to "Clicked!" |
| Type | `[{op:"type", selector:"#typer", value:"hello"}]` | Input contains "hello" |
| Read | `[{op:"getTextContent", selector:"#clicker"}]` | `data.textContent = "Click me"` |
| Wait | `[{op:"waitForSelector", selector:"#clicker"}]` | Succeeds immediately |
| Timeout | `[{op:"waitForSelector", selector:"#nonexistent", timeoutMs:2000}]` | Fails after 2s |

Observe the entire sequence in Daemon8:
```
debug_checkpoint()
# ... trigger actions ...
debug_observe(since_checkpoint=<id>, origins=["app:agent-bridge"], text_match="action")
```

**Test coverage added in this phase:**

| Component | Test Type | Description |
|---|---|---|
| Action step executor | Unit (Vitest + jsdom/happy-dom) | Each of 16 ops against mock DOM |
| `dom.request` / `dom.response` | Unit (Vitest) | Query elements, return attributes |
| `TabScope::executeAction` | Integration (PHPUnit + mock WS) | Send action, receive result, verify Deferred resolution |
| `TabScope::queryDom` | Integration (PHPUnit + mock WS) | Send dom.request, receive dom.response |
| Action timeout | Integration (PHPUnit) | No response within timeout, verify cancel sent |
| `LegoDefinition` | Unit (PHPUnit) | Confidence computation, immutable builders, JSON round-trip |
| `LegoLibrary` | Unit (PHPUnit) | File system round-trips, domain isolation |

---

## Phase 5: Intelligence -- AI Classification and Lego System

**Goal:** The stream pipeline feeds DOM batches to the ClassifierAgent. The classifier maps DOM state to known legos. The LegoExecutor runs matched legos. The full observe-classify-act loop is visible.

**Dependencies:** Phase 4 (actions working end-to-end)

**Duration:** 3-4 sessions

### 5.1 AI Agent Integration (D6)

**Agent:** `async-php84-phalanx-native-expert`

**Deliverables:**
- `daemon/src/Agent/ClassifierAgent.php` -- Receives DOM batch + available legos, returns classification decisions
- `daemon/src/Agent/ClassifyElements.php` -- AI tool: accepts `classifications` array, validates, returns
- `daemon/src/Agent/GeneratorAgent.php` -- Receives user intent + DOM snapshot, generates lego definitions
- `daemon/src/Agent/CreateLegos.php` -- AI tool: accepts lego definitions, validates steps
- `daemon/src/Agent/ValidateSelector.php` -- AI tool: validates CSS selector against live DOM via `queryDom`
- `daemon/src/Agent/RepairAgent.php` -- Receives broken lego + current DOM, returns repaired steps
- `daemon/src/Agent/RepairLego.php` -- AI tool: accepts repaired steps
- `AiServiceBundle` -- Registers AI provider config from `$context`
- Replace classify stub in `TabScope::startPipeline` with real `ClassifierAgent` invocation
- `TabScope::handleUserChat()` -- Routes `user.chat` to `GeneratorAgent` (**Spec prerequisite:** `user.chat` wire protocol type added to integration/SPEC.md Section 1.1, TypeScript type in extension/SPEC.md, daemon routing in daemon/SPEC.md Section 5.2)

**Verification:**

Test with mock AI provider first (predetermined responses):
```bash
# Set AI provider to mock
AI_PROVIDER_DEFAULT=mock php daemon/bin/bridge

# Connect a tab, trigger DOM mutations
# Daemon trace shows:
# EXEC ClassifierAgent  batch_size=12
# DONE ClassifierAgent  classifications=2 +150ms  (mock responds instantly)
# EXEC LegoExecutor     lego=archive_email
# <- action.execute     tabId=42 actionId=act_1 steps=[...]
# -> action.result      tabId=42 actionId=act_1 success=true
# DONE LegoExecutor     lego=archive_email success=true
```

Test with real AI provider:
```bash
# Set AI provider to Anthropic
AI_PROVIDER_DEFAULT=anthropic ANTHROPIC_API_KEY=sk-ant-... php daemon/bin/bridge

# Connect Gmail tab, send a chat message:
# "Archive all promotional emails"

# Daemon trace shows the full cycle:
# EXEC handleUserChat  text="Archive all promotional emails"
# EXEC GeneratorAgent
# EXEC ValidateSelector  selector=[data-tooltip="Archive"]
# -> dom.request       tabId=42 requestId=dreq_1
# <- dom.response      tabId=42 requestId=dreq_1 elements=[...]
# DONE ValidateSelector  matchCount=1 stable=true
# EXEC CreateLegos      legos=2
# DONE GeneratorAgent    legos=2 +3.2s
# [saved] archive_email -> ~/.phalanx/legos/mail.google.com/archive_email.json
```

Observe AI latency impact on backpressure:
```
debug_observe(origins=["app:agent-bridge"], text_match="throttle")
# If AI is slow (>2s), backpressure should engage:
# flow.throttle sent to extension
# flow.resume sent when AI catches up
```

### 5.2 Policy Store (D8)

**Deliverables:**
- `daemon/src/Policy/DomainPolicy.php` -- Per-domain policy with user action log, override tracking
- `daemon/src/Policy/PolicyRule.php` -- Serializable policy rule with threshold computation
- `daemon/src/Policy/PolicyStore.php` -- File-based storage at `~/.phalanx/policies/{domain}/`
- `TabScope::handleUserAction()` -- Records user actions to policy store

### 5.3 Milestone: Full AI Cycle

The culmination: DOM snapshot flows to classifier, classifier selects lego, executor runs it, result flows back, confidence updates in side panel.

**Verification:**

This is the first time the full system works end-to-end. Use a real website.

| Step | Observable At | What You See |
|---|---|---|
| Page loads | Protocol logger | `dom.snapshot` with HTML |
| Mutations stream | Daemon8 | Batched mutation events |
| Classifier runs | Daemon trace | `EXEC ClassifierAgent` with batch contents |
| Lego selected | Daemon trace | Classification result with confidence |
| Action sent | Protocol logger | `action.execute` with steps |
| DOM changes | Daemon8 CDP | `debug_observe` shows page mutations |
| Result returns | Daemon trace | `DONE LegoExecutor` with success/failure |
| Side panel updates | Extension UI | Confidence meter updates |

**Test coverage added in this phase:**

| Component | Test Type | Description |
|---|---|---|
| `ClassifierAgent` | Unit (PHPUnit + mock AI) | Predetermined classifications, verify action pipeline |
| `GeneratorAgent` | Unit (PHPUnit + mock AI) | Predetermined lego generation, verify file save |
| `ValidateSelector` | Integration (PHPUnit + mock WS) | Selector validation round-trip |
| `RepairAgent` | Unit (PHPUnit + mock AI) | Repair flow with broken lego input |
| `DomainPolicy` | Unit (PHPUnit) | Action logging, override tracking, rule updates |
| `PolicyStore` | Unit (PHPUnit) | File system round-trips, domain lookup |

---

## Phase 6: Resilience -- Reconnection, Flow Control, Edge Cases

**Goal:** The system handles every failure mode in the integration spec's failure catalog. Disconnections are graceful. Reconnection restores state. Flow control prevents overload.

**Dependencies:** Phase 5 (full AI cycle working)

**Duration:** 2-3 sessions

### 6.1 Extension Reconnection (E6)

**Agent:** `plasmo-extension-expert`

**Deliverables:**
- Service worker detects WebSocket close, persists state, reconnects with exponential backoff + jitter
- On reconnect: resend `tab.connect` with current URL/title (from `chrome.tabs.get()`, not stale storage)
- Tell content scripts to resend `dom.snapshot` via `__resend_snapshot` internal message
- Content script buffer flush on port reconnect
- `chrome.storage.local` backup for extension update survival
- Hybrid reconnect timer: `setTimeout` for <5s delays, `chrome.alarms` for >=5s

### 6.2 Extension Flow Control (E5)

**Agent:** `plasmo-extension-expert`

**Deliverables:**
- Content script buffer depth tracking and `flow.pressure` emission
- `flow.throttle` response: adjust rAF frame skip (`throttleFrameSkip`)
- `flow.throttle maxEventsPerSec=0`: disconnect observer, re-enable on `flow.resume` + send fresh snapshot
- Service worker `bufferedAmount` monitoring: pause at 1MB, resume at 512KB

### 6.3 Daemon Flow Control (D7)

**Agent:** `async-php84-phalanx-native-expert`

**Deliverables:**
- Channel `withPressure` callback wired to send `flow.throttle` / `flow.resume`
- `TabScope::handleFlowControl()` -- Logs `flow.pressure` from extension
- Verify backpressure propagation: Channel full -> TCP flow control -> extension throttled

### 6.4 Milestone: Resilience

**Verification:**

| Scenario | Action | Expected Outcome |
|---|---|---|
| WebSocket drop | Kill daemon mid-stream | Extension reconnects, resends tab.connect, daemon creates fresh TabScopes |
| Rapid mutations | Load page with heavy DOM churn | `flow.throttle` sent, content script reduces frequency, `flow.resume` after drain |
| Action timeout | Send action to disconnected content script | Daemon times out after 30s, sends `action.cancel` |
| Service worker restart | Toggle extension off/on at chrome://extensions | Restores from session storage, reconnects, resends state |
| Extension update | Increment version, reload extension | Restores from local storage backup, reconnects |
| Navigation during action | Navigate tab while action is executing | `action.result` fails or TabScope disposes, no crash |

**Test coverage added in this phase:**

| Component | Test Type | Description |
|---|---|---|
| Reconnection | Integration (mock server) | Connect, drop, reconnect, verify re-registration |
| State reconciliation | Integration | After reconnect, verify fresh snapshots received |
| Flow control e2e | Integration (PHPUnit) | Rapid-fire messages, verify throttle/resume cycle |
| Buffer management | Unit (Vitest) | Buffer overflow, flush on reconnect |

---

## Phase 7: Polish -- Side Panel UI and User Experience

**Goal:** The side panel shows connection status, per-action confidence, and agent conversation. The user can chat with the agent to create new legos.

**Dependencies:** Phase 5 (AI integration), Phase 6 (resilience)

**Duration:** 2-3 sessions

### 7.1 Side Panel Components (E4 -- full)

**Agent:** `plasmo-extension-expert`

**Deliverables:**
- `ConfidenceDisplay.tsx` -- Per-action confidence meters for selected tab
- `AgentConversation.tsx` -- Chat feed showing agent messages, user input
- `extension/src/background/messages/send-chat.ts` -- Forwards user chat to daemon as `user.chat`
- `extension/src/background/messages/get-state.ts` -- Side panel requests current state
- `ui.update` dispatch: `status`, `confidence`, `conversation` target handling
- Filtered `chrome.tabs.onUpdated` listener (only re-query on URL/title/status changes, debounced)

### 7.2 Native Messaging Host (System Plumbing)

**Agent:** `bridge-integration-architect`

This is system plumbing, not UI. Build when the extension needs to discover the daemon port automatically instead of using a hardcoded fallback. Can be done earlier if hardcoded port becomes painful during development.

**Deliverables:**
- `daemon/native-host/com.phalanx.bridge.sh` -- Shell script that reads `~/.phalanx/daemon.lock`, returns `{"wsUrl":"ws://localhost:{port}/bridge"}`
- `daemon/native-host/com.phalanx.bridge.json` -- Native messaging manifest for macOS
- Installation script for placing manifest in `~/Library/Application Support/Google/Chrome/NativeMessagingHosts/`

**Verification:**
```bash
# Install the native host manifest
bash daemon/native-host/install.sh

# Start daemon (writes lockfile)
php daemon/bin/bridge

# Test from Chrome DevTools console (service worker):
chrome.runtime.connectNative("com.phalanx.bridge")
# Should receive: {"wsUrl":"ws://localhost:9078/bridge"}
```

### 7.3 LaunchAgent / systemd Service (System Plumbing)

Same as 7.2 -- system plumbing. Build when manual daemon start becomes tedious.

**Deliverables:**
- `daemon/install/com.phalanx.agent-bridge.plist` -- macOS LaunchAgent
- `daemon/install/phalanx-bridge.service` -- Linux systemd user service
- `daemon/install/install.sh` -- Places files, registers services

---

## Observability Guide

### During Development

| What You Want to See | Tool | Command |
|---|---|---|
| Every wire message | Protocol logger | `npx tsx tools/protocol-logger.ts --listen 9079 --forward ws://localhost:9078/bridge` |
| Daemon execution trace | Phalanx Trace | `PHALANX_TRACE=1 php daemon/bin/bridge` |
| Daemon events in Daemon8 | Daemon8 MCP | `debug_observe(origins=["app:agent-bridge"])` |
| Browser console logs | Daemon8 MCP | `debug_observe(origins=["browser"], kinds=["log"])` |
| Browser network requests | Daemon8 MCP | `debug_observe(origins=["browser"], kinds=["http_exchange"])` |
| Extension service worker state | Chrome DevTools | chrome://extensions -> Inspect service worker |
| Content script state | Chrome DevTools | F12 on the page -> Console |
| Chrome storage contents | Chrome DevTools | Application tab -> Storage -> Extension storage |
| Live DOM changes after action | Daemon8 MCP | `debug_act(action="get_dom", selector="body")` |
| Side panel renders correctly | Browser | Open side panel, interact |

### Incremental Polling Pattern

Use Daemon8 checkpoints to watch for new events without re-fetching history:

```
# At the start of a test
checkpoint_id = debug_checkpoint()

# ... run the test scenario ...

# See only what happened during the test
debug_observe(since_checkpoint=checkpoint_id, origins=["app:agent-bridge"])
```

### Production Monitoring

| Metric | Source | Alert Condition |
|---|---|---|
| WebSocket connections | Daemon8 observation count | Drop to 0 when tabs expected |
| Classification latency | Daemon trace `ClassifierAgent` duration | >10s sustained |
| Action failure rate | Daemon trace `LegoExecutor` fail count | >3 consecutive failures per lego |
| Memory usage | Daemon trace memory sampling | Growth without plateau |
| Reconnection frequency | Extension state changes | >5/hour indicates instability |

---

## Agent Assignment Summary

| Phase | Primary Agent | Reasoning |
|---|---|---|
| 0: Test Infrastructure | `bridge-integration-architect` | Protocol tooling crosses both sides |
| 1: Wire Protocol (daemon) | `async-php84-phalanx-native-expert` | Pure PHP data types + WS handler |
| 1: Wire Protocol (extension) | `plasmo-extension-expert` | TypeScript types + Plasmo setup |
| 2: Tab Lifecycle | Both | Integration milestone requires both |
| 3: DOM Streaming | Both | Content script observation + daemon pipeline |
| 4: Actions | Both | Content script executor + daemon correlation |
| 5: AI Integration | `async-php84-phalanx-native-expert` | Phalanx AI tools, agent loop, scope threading |
| 6: Resilience | `plasmo-extension-expert` (lead) | Chrome platform lifecycle dominates |
| 7: Polish | `plasmo-extension-expert` | React UI components |
| Cross-cutting: Observability | `daemon8-runtime-engineer` | Daemon8 integration, telemetry pipeline |

---

## Risk Register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Plasmo build output path differs from spec | High | Content script injection fails | Phase 2: test programmatic injection early. Use activation message to already-injected script. |
| AI classification too slow for real-time feel | Medium | User sees multi-second delays | Phase 5: implement with mock AI first, measure real latency, tune `bufferWindow` parameters |
| Service worker killed during long AI cycle | Medium | Interrupted communication | Phase 6: native messaging keepalive + chrome.alarms heartbeat tested explicitly |
| Chrome Web Store rejects `<all_urls>` | Medium | Cannot publish | Document user-consent flow in screenshots. Emphasize explicit tab connection gesture. |
| MutationObserver produces too much data on complex SPAs | High | Backpressure constantly engaged | Phase 3: measure actual throughput on React/Angular apps. Tune throttle and bufferWindow. |
| Lego selectors break when sites update | Certain | Actions fail | Phase 5: RepairAgent handles this. Monitor failure rate, auto-trigger repair. |
| `evaluate` return value not structured-cloneable | Medium | `evaluate` op throws `DataCloneError` | Phase 4: document serialization constraints. Expressions must return primitives, plain objects, or arrays. DOM nodes, functions, Promises, and Symbols are not cloneable. |
| DOM snapshots exceed 4MB WebSocket frame limit | Low | Message dropped | Phase 3: scope snapshots to smaller subtrees. Add `dom.configure` message in v2. |
| Multiple Chrome profiles connect simultaneously | Low | Tab ID collision | v1: single profile supported. Log warning on duplicate tabId from different session. |

---

## Dependency Graph

```
Phase 0: Test Infrastructure
    |
    v
Phase 1: Wire Protocol (daemon + extension in parallel)
    |
    v
Phase 2: Tab Lifecycle (first integration)
    |
    v
Phase 3: DOM Streaming
    |
    v
Phase 4: Actions
    |
    v
Phase 5: AI Integration
    |
    v
Phase 6: Resilience
    |
    v
Phase 7: Polish
```

Phases 1-daemon and 1-extension run in parallel with mock counterparts. Phase 2 is the first integration point. After Phase 2, both sides must be running together for integration milestones.

---

## Quick Reference: Spec Locations

| Document | Path | Lines | Content |
|---|---|---|---|
| Wire protocol | `integration/SPEC.md` | 764 | Message types, failure modes, backpressure, build plan |
| Daemon internals | `daemon/SPEC.md` | 1215 | Process lifecycle, services, stream pipeline, AI tools |
| Extension internals | `extension/SPEC.md` | 2168 | Plasmo structure, content script, service worker, side panel |
| Product spec | `SPEC.md` | 1798 | Original product spec with PHP class designs |
| Integration review | `integration/REVIEW.md` | 495 | 22 issues, 3 critical |
| Daemon review | `daemon/REVIEW.md` | ~400 | Daemon-specific corrections |
| Extension review | `extension/REVIEW.md` | 451 | Platform reality, Plasmo issues |
