# Phalanx Agent Bridge -- Integration Spec

Shared contract between the Phalanx daemon (../daemon/SPEC.md) and Chrome extension (../extension/SPEC.md). This document is the single source of truth for everything that crosses the WebSocket boundary.

When this spec and a sibling spec disagree, this spec wins.

---

## 1. Wire Protocol Reference

All messages are JSON objects sent as WebSocket text frames. Every message has a `type` field. Fields with `?` suffix are optional. There are no envelope wrappers, no sequence numbers, and no protocol version headers.

Protocol evolution rule: new message types may be added. Existing message types and their required fields are never modified. Both sides must silently ignore unknown `type` values.

### 1.1 Extension to Daemon Messages

#### Tab Lifecycle

| Type | Fields | Semantics |
|------|--------|-----------|
| `tab.connect` | `tabId: int`, `sessionId: string`, `url: string`, `title: string`, `domain: string` | Content script activated on a tab. `sessionId` is `chrome.runtime.id`, stable per extension install but unique across Chrome profiles/browsers. Daemon creates TabScope keyed by `sessionId:tabId` composite. Duplicate composite key for an already-connected tab is silently ignored (idempotent). |
| `tab.disconnect` | `tabId: int` | Tab closed, content script unloaded, or user explicitly disconnected. Daemon disposes TabScope, cancels all in-flight actions, rejects all pending deferreds. Idempotent -- disconnecting an unknown tab is a no-op. |
| `tab.navigate` | `tabId: int`, `url: string`, `title: string` | Same-tab navigation (SPA pushState or full page load). Daemon updates TabScope url/title. Does NOT trigger reconnect -- the content script survives SPA navigations. For full page loads, the content script is destroyed and re-injected by the extension; the extension sends `tab.disconnect` followed by `tab.connect` with the same `tabId`. |

#### DOM Events

| Type | Fields | Semantics |
|------|--------|-----------|
| `dom.snapshot` | `tabId: int`, `html: string`, `selector: string`, `timestamp: int` | Scoped HTML snapshot of a DOM subtree. Never full-page HTML. Sent on initial connect, after navigation, and in response to `dom.request`. `timestamp` is Unix epoch milliseconds from the content script's `performance.now()` + origin. |
| `dom.mutations` | `tabId: int`, `mutations: array`, `timestamp: int` | Batched, summarized DOM mutations. Each mutation: `{type: "childList"|"attributes"|"characterData", target: string(selector), addedCount?: int, removedCount?: int, attr?: string, value?: string}`. The content script debounces by animation frame and filters invisible elements before batching. |
| `dom.response` | `tabId: int`, `requestId: string`, `elements: array` | Reply to a daemon `dom.request`. Each element is a flat object of requested attribute key-value pairs. Empty array if selector matched nothing. **Routing note:** This is a request-reply message, not a stream event. The daemon must route `dom.response` to the pending Deferred keyed by `requestId` in `TabScope::$pendingActions` -- it must NOT enter the stream pipeline. The daemon's message router must match `dom.response` before the generic `dom.*` prefix to avoid misrouting. |

#### Network Events

| Type | Fields | Semantics |
|------|--------|-----------|
| `net.request` | `tabId: int`, `requestId: string`, `method: string`, `url: string`, `timestamp: int` | Outbound network request observed by the service worker. `requestId` is opaque, unique within the tab session. |
| `net.response` | `tabId: int`, `requestId: string`, `url: string`, `status: int`, `contentType: string`, `bodyPreview: string?`, `durationMs: int`, `timestamp: int` | Completed response. `url` is the original request URL, included for correlation convenience since `requestId` is opaque. `bodyPreview` is the first 4096 bytes of text-based responses. Absent for binary responses or when body capture is disabled. **v1 limitation:** MV3 `chrome.webRequest` does not expose response bodies. `bodyPreview` is always absent in v1. Capturing bodies requires MAIN world fetch/XHR interception (v2 enhancement). The daemon must treat `bodyPreview` as absent by default. |

#### User Actions

| Type | Fields | Semantics |
|------|--------|-----------|
| `user.action` | `tabId: int`, `action: string`, `target: string`, `value: string?`, `timestamp: int` | User interaction observed by the content script. `action` is one of: `click`, `type`, `select`, `scroll`, `submit`. `target` is a CSS selector identifying the element. `value` is the typed/selected value where applicable. Feeds the policy learning engine. |

#### User Chat

| Type | Fields | Semantics |
|------|--------|-----------|
| `user.chat` | `tabId: int`, `text: string` | User message from the side panel conversation UI. The daemon routes this to the GeneratorAgent or responds conversationally via `ui.update` with target `conversation`. This is how users express intent ("archive all newsletters", "export this table to CSV") that triggers lego generation. |

#### Action Results

| Type | Fields | Semantics |
|------|--------|-----------|
| `action.result` | `tabId: int`, `actionId: string`, `success: bool`, `data: object?`, `error: string?` | Result of executing an `action.execute` command. Sent once per `actionId` when the step sequence completes or fails. `data` contains return values from read ops (`getAttribute`, `getTextContent`, `evaluate`). `error` contains a human-readable failure reason. An `action.result` for an unknown `actionId` (cancelled, timed out on daemon side) is silently ignored by the daemon. |

#### Flow Control

| Type | Fields | Semantics |
|------|--------|-----------|
| `flow.pressure` | `tabId: int`, `bufferDepth: int` | Content script's outbound message queue depth. Sent when buffer depth crosses a threshold (default: 16 messages). The daemon uses this to decide whether to send `flow.throttle`. Not sent when buffer is empty or draining normally. |

### 1.2 Daemon to Extension Messages

#### Action Commands

| Type | Fields | Semantics |
|------|--------|-----------|
| `action.execute` | `tabId: int`, `actionId: string`, `steps: array` | Execute a sequence of DOM operations on the specified tab. Steps are executed sequentially by the content script. If any step fails, execution halts and `action.result` reports the failure. `actionId` is generated by the daemon (format: `act_{n}`), unique within the TabScope lifetime. See Section 1.3 for step operations. |
| `action.cancel` | `tabId: int`, `actionId: string` | Cancel an in-progress action. The content script stops executing further steps and sends `action.result` with `success: false, error: "cancelled"`. If the action already completed, the cancel is silently ignored. |

#### DOM Requests

| Type | Fields | Semantics |
|------|--------|-----------|
| `dom.request` | `tabId: int`, `requestId: string`, `selector: string`, `attrs: string[]?`, `limit: int?` | Request structured DOM data. The content script runs `querySelectorAll(selector)`, extracts requested attributes (or all `data-*` attributes if `attrs` is null), and responds with `dom.response`. `limit` caps the number of elements returned. `requestId` format: `dreq_{n}`. |

#### UI Updates

| Type | Fields | Semantics |
|------|--------|-----------|
| `ui.update` | `target: string`, `data: object` | Update the side panel UI. No `tabId` at the message level -- `tabId` is inside `data` when relevant. `target` is one of: `status`, `confidence`, `conversation`. The service worker forwards this to the side panel verbatim. |

`ui.update` target payloads:

| Target | Data Fields | Purpose |
|--------|-------------|---------|
| `status` | `tabId: int`, `state: string`, `domain?: string`, `legoCount?: int` | Tab connection state change |
| `confidence` | `tabId: int`, `action: string`, `confidence: float`, `executions: int`, `overrides: int` | Per-action confidence update |
| `conversation` | `tabId: int`, `role: string`, `text: string` | Agent message for conversation UI |

#### Flow Control

| Type | Fields | Semantics |
|------|--------|-----------|
| `flow.throttle` | `tabId: int`, `maxEventsPerSec: int` | Instruct the content script to reduce observation frequency. The content script increases its debounce interval for DOM mutations and reduces network event forwarding to stay under the cap. A value of 0 means pause all non-essential events (action results and dom.response are never suppressed). |
| `flow.resume` | `tabId: int` | Lift throttling. Content script returns to normal observation frequency. Implicitly sent when a throttled tab's Channel buffer drains below 50%. |

### 1.3 Action Step Operations

The `steps` array in `action.execute` uses a fixed set of primitive operations dispatched by `op` string. Each step is an object with `op` as the discriminator.

| Op | Required Fields | Optional Fields | Behavior | Returns |
|----|----------------|-----------------|----------|---------|
| `click` | `selector` | -- | `querySelector`, `scrollIntoView`, `.click()`. Fails if element not found. | -- |
| `clickAll` | `selector` | `delayMs: int` | `querySelectorAll`, click each with optional delay (default 0) between clicks. | -- |
| `type` | `selector`, `value` | `mainWorld: bool` | Focus element, clear existing value, type `value` character by character with synthetic key events. When `mainWorld` is true (default false), routes through the service worker for MAIN world execution via `chrome.scripting.executeScript`. Used when ISOLATED world execution fails on framework-controlled inputs (React, Angular, Vue). | -- |
| `fill` | `selector`, `value` | `mainWorld: bool` | Focus element, set `.value` property directly, dispatch `input` and `change` events. Faster than `type` but skips key events. When `mainWorld` is true (default false), routes through the service worker for MAIN world execution. Used when ISOLATED world `.value=` doesn't trigger framework state updates. | -- |
| `select` | `selector`, `value` | -- | Set `<select>` element's value, dispatch `change` event. | -- |
| `check` | `selector`, `checked` | -- | Set checkbox/radio `checked` property, dispatch `change` event. `checked` is boolean. | -- |
| `press` | `key` | -- | Dispatch `keydown` + `keypress` + `keyup` for the named key (e.g., `"Enter"`, `"Escape"`, `"Tab"`). Uses `KeyboardEvent` constructor. | -- |
| `scroll` | `selector` | `x: int`, `y: int` | Scroll element to coordinates. If no coordinates, `scrollIntoView({behavior: 'smooth'})`. | -- |
| `waitForSelector` | `selector` | `timeoutMs: int` | Poll until element exists in DOM. Default timeout 5000ms. Polling interval: `requestAnimationFrame`. | -- |
| `waitForRemoval` | `selector` | `timeoutMs: int` | Poll until element no longer exists. Default timeout 5000ms. | -- |
| `waitForText` | `selector`, `text` | `timeoutMs: int` | Poll until element's `textContent` contains `text` (substring match, case-sensitive). Default timeout 5000ms. | -- |
| `waitForNetwork` | `urlPattern` | `timeoutMs: int` | Wait for a network request matching the URL pattern (glob) to complete. Default timeout 10000ms. **Cross-context:** The content script cannot observe network events directly. It sends a `__waitForNetwork` internal message to the service worker via port, which monitors `chrome.webRequest` and responds when a matching request completes. This is an internal extension protocol, not a daemon wire message. | -- |
| `getAttribute` | `selector`, `attr` | -- | Return element's attribute value. Fails if element not found. | `string` via `data` |
| `getTextContent` | `selector` | -- | Return element's `textContent`. Fails if element not found. | `string` via `data` |
| `evaluate` | `expression` | -- | Execute a JS expression in the page's MAIN world via `chrome.scripting.executeScript({ world: 'MAIN' })`. The content script routes this through the service worker (see `__evaluate` internal protocol in extension spec). HAS access to page-context globals (`window.*`, framework state, etc.). Return values must be structured-cloneable -- DOM nodes, functions, Promises, and Symbols throw `DataCloneError`. Expressions must be synchronous. Escape hatch for operations not covered by other ops. | `mixed` via `data` |
| `delay` | `ms` | -- | Sleep for `ms` milliseconds. Pure timer, no DOM interaction. | -- |

Step execution is strictly sequential within one `action.execute`. No parallelism. If a step with a `timeoutMs` expires, the entire action fails.

Read operations (`getAttribute`, `getTextContent`, `evaluate`) return their values in the `action.result` message's `data` field. For multi-step sequences with multiple reads, `data` contains only the last read's result. If intermediate read values are needed, split into separate `action.execute` calls.

#### v1 Known Constraints

| Constraint | Impact | Future Resolution |
|---|---|---|
| Shadow DOM (closed roots) | `querySelector` cannot pierce closed shadow roots. Elements inside are invisible to all ops that take a `selector`. | `evaluate` op with MAIN world access can reach shadow DOM via `element.shadowRoot` on open roots. Closed roots require host cooperation or CDP. |
| Cross-origin iframes | Content script cannot access DOM inside cross-origin iframes. Actions targeting iframe content silently fail (`Element not found`). | `chrome.scripting.executeScript` with `frameIds` parameter can target specific frames. Requires knowing the frame ID. |
| Navigation race during action | A `click` step that triggers full navigation destroys the content script mid-action. No `action.result` arrives. | Service worker detects port disconnect and synthesizes `action.result` with `success: false, error: "navigation"`. Daemon already handles missing results via timeout. |
| `isTrusted:false` on all dispatched events | All DOM events dispatched by the content script (click, input, key events) have `isTrusted: false`. Sites checking `event.isTrusted` will ignore them. | CDP `Input.dispatchMouseEvent` / `Input.dispatchKeyEvent` is the only path to `isTrusted: true`. Reserved for Phase 6.5+/v2. |

The `mainWorld` flag on `fill`, `type`, and `evaluate` ops enables MAIN world execution for steps that fail in ISOLATED world. The `LegoDefinition` schema carries this flag per step. When a lego's `fill` or `type` step fails on a framework-controlled input, the RepairAgent sets `mainWorld: true` on that step and associates the pattern with the domain's hint cache. The `evaluate` op always executes in MAIN world regardless of this flag.

### 1.4 Message Ordering Guarantees

**Within a single WebSocket connection:**
- Messages from the same sender arrive in send order (WebSocket protocol guarantee).
- The extension sends messages from multiple content scripts via the service worker. The service worker serializes sends to the single WebSocket, so inter-tab message ordering reflects the service worker's processing order, not the content scripts' observation order.
- The daemon processes inbound messages sequentially per `BridgeGateway::pump()` loop iteration. A message is fully routed before the next is read.

**No global ordering across reconnections:**
- After a WebSocket drop and reconnect, there is no relationship between pre-drop and post-drop message sequences. The reconnect starts fresh.

**No delivery guarantee:**
- Messages in the WebSocket send buffer at the time of disconnection are lost. Neither side implements acknowledgment or replay.

### 1.5 Idempotency Requirements

| Message | Idempotent | Notes |
|---------|-----------|-------|
| `tab.connect` | Yes | Duplicate connect for existing tabId is ignored. |
| `tab.disconnect` | Yes | Disconnecting unknown tabId is a no-op. |
| `tab.navigate` | Yes | Sets state to latest values. |
| `dom.snapshot` | No | Each snapshot is a point-in-time observation. |
| `dom.mutations` | No | Mutations are incremental and not replayable. |
| `dom.response` | No | Reply to a specific request. Duplicate requestId resolves same deferred twice (second is no-op since deferred already resolved). |
| `net.request` | No | Each is a distinct network event. |
| `net.response` | No | Each is a distinct network event. |
| `user.action` | No | Each is a distinct user interaction. |
| `user.chat` | No | Each is a distinct user message. |
| `action.result` | Effectively yes | Resolves or rejects a deferred. Second result for same actionId hits no pending deferred and is silently dropped. |
| `flow.pressure` | Yes | Reports current state, not delta. |
| `action.execute` | No | Each execution is a distinct command with a unique actionId. |
| `action.cancel` | Yes | Cancelling already-completed or unknown actionId is a no-op. |
| `dom.request` | No | Each request gets a unique requestId. |
| `ui.update` | Yes | Overwrites previous state for the same target+tabId. |
| `flow.throttle` | Yes | Latest value wins. |
| `flow.resume` | Yes | Idempotent lift. |

---

## 2. Failure Mode Catalog

Every component can crash. Every connection can drop. This section documents what happens for each scenario, not just that it is handled.

### 2.1 WebSocket Drops Silently

**Detection:**
- Daemon: WebSocket ping/pong timeout. The daemon sends WebSocket-level ping frames every 15 seconds. If no pong is received within 30 seconds, the connection is considered dead. Additionally, the `BridgeGateway::pump()` loop terminates when the inbound channel completes (connection closed by transport layer).
- Extension: The service worker's `WebSocket.onclose` event fires. If the close was not clean (no close frame received), the service worker detects via `CloseEvent.wasClean === false`.

**What each side does:**

Daemon:
1. `WsConnectionHandler` closes the connection channels.
2. `BridgeGateway`'s `pump()` loop exits.
3. `WsScope::onDispose` fires, calling `TabManager::unregisterSession()`.
4. All TabScopes owned by that session are disposed: pending action deferreds rejected with "Tab disconnected", inbound channels completed, cancellation tokens cancelled, child scopes disposed.
5. All state for the session is gone. No attempt to preserve tab state for potential reconnection.

Extension:
1. `WebSocket.onclose` fires in the service worker.
2. Service worker persists `connectedTabs` to `chrome.storage.session` (tab IDs, URLs, titles).
3. Service worker begins reconnection attempts: 1s, 2s, 4s, 8s, 16s, 30s max backoff with jitter (random 0-1s added).
4. Content scripts continue running but buffer outbound messages locally (bounded: 64 messages, drop oldest on overflow).
5. Side panel shows "Reconnecting..." status.

**State lost:** All in-flight actions, all pending DOM requests, all stream pipeline state, all Channel buffer contents on the daemon side. The extension's content scripts retain their DOM observers and local state.

**Recovery:** See Section 3 (State Reconciliation Protocol).

### 2.2 Daemon Process Crashes

**Detection:**
- Extension: WebSocket `onclose` fires (TCP FIN or RST). If the process was killed hard (SIGKILL), the OS closes the socket.
- Daemon: Not applicable (it is the crashed party). On restart, the daemon reads no existing state -- it starts fresh.

**What each side does:**

Extension:
1. Same as 2.1 from the extension's perspective -- it cannot distinguish a daemon crash from a network drop.
2. Reconnection attempts will fail until the daemon restarts and begins listening.
3. If reconnection fails for 60 seconds, the side panel shows "Daemon offline. Restart the daemon or check the installation."

Daemon (on restart):
1. Writes new `daemon.lock` with (potentially different) port and PID.
2. Listens on the new port.
3. Has no memory of previous connections, tabs, or actions.

**State lost:** Everything on the daemon side -- tab scopes, lego execution state, stream pipelines, in-memory policy state. File-based storage (LegoLibrary, PolicyStore) survives on disk.

**Recovery:** Extension must re-bootstrap via Native Messaging to discover the new port, then reconnect and resend `tab.connect` for all active tabs.

### 2.3 Service Worker Terminates

Chrome terminates service workers after 30 seconds of inactivity (no pending events, no active connections). The `connectNative()` port and WebSocket connection are the primary keepalive mechanisms.

**Detection:**
- Daemon: WebSocket closes (Chrome closes sockets when the service worker dies). Same as 2.1.
- Extension: On service worker restart, `chrome.storage.session` contains the previous state. The service worker detects it was restarted by checking for existing state in session storage.

**What each side does:**

Daemon: Same as 2.1.

Extension (on service worker restart):
1. Reads `connectedTabs` from `chrome.storage.session`.
2. Re-establishes `connectNative()` for keepalive.
3. Re-reads daemon port from Native Messaging response.
4. Re-opens WebSocket to daemon.
5. For each previously connected tab: verifies the tab still exists via `chrome.tabs.get(tabId)`, re-sends `tab.connect` if valid, removes from storage if tab was closed while service worker was dead.
6. Content scripts that were already running continue to run. The service worker re-establishes port connections to them.

**State lost:** All in-memory service worker state (but this should be minimal -- connectedTabs map is persisted to session storage on every mutation). The daemon loses all session state (same as any disconnection).

**Mitigation:** The `connectNative()` port is the primary keepalive. As long as this port stays open, Chrome will not terminate the service worker. The WebSocket provides secondary keepalive (Chrome 116+). The combination means the service worker should rarely be terminated during normal operation.

### 2.4 Content Script Unloaded

Content scripts are unloaded when: the tab navigates to a different origin (full navigation), the tab is closed, or the extension is updated/reloaded.

**Detection:**
- Service worker: `chrome.runtime.Port.onDisconnect` fires for the content script's port. For tab close, `chrome.tabs.onRemoved` also fires.
- Daemon: Receives `tab.disconnect` (if service worker sends it) or detects via absence of events from that tab.

**What each side does:**

Service worker:
1. On port disconnect: checks if tab still exists via `chrome.tabs.get()`.
2. Tab closed: sends `tab.disconnect` to daemon, removes from `connectedTabs` storage.
3. Tab navigated (full): sends `tab.disconnect` for old context. If the new page is within the scope the user connected, re-injects content script and sends new `tab.connect`.
4. Extension updated: all content scripts die. On service worker restart, re-injects into all previously connected tabs.

Daemon: Receives `tab.disconnect`, disposes TabScope. Normal cleanup.

**State lost:** Content script's MutationObserver state, local message buffer, any pending action execution mid-step.

### 2.5 Action Timeout

An `action.execute` command contains steps with optional `timeoutMs` fields. The content script enforces step-level timeouts. The daemon enforces an overall action timeout.

**Detection:**
- Content script: A `waitFor*` step exceeds its `timeoutMs`. The content script stops executing further steps and sends `action.result` with `success: false, error: "Timeout waiting for selector: .foo (5000ms)"`.
- Daemon: If no `action.result` arrives within the daemon-side timeout (default 30 seconds, configurable per action), the pending deferred is rejected with a timeout error. The daemon sends `action.cancel` to the extension to stop any still-executing steps.

**What each side does:**

Content script: Stops execution, reports failure.

Daemon:
1. The deferred in `TabScope::$pendingActions` is rejected.
2. The LegoExecutor records a failure against the LegoDefinition.
3. If the lego's failure rate exceeds a threshold (3 consecutive failures), the RepairAgent is invoked with a fresh DOM snapshot.
4. The side panel is updated with the failure reason via `ui.update` conversation message.

**State lost:** The partially-executed action's effects on the page remain (clicks already dispatched, text already typed). There is no rollback mechanism for DOM mutations.

### 2.6 Navigation During In-Flight Action

The user or the page itself navigates while the content script is executing action steps.

**Detection:**
- Content script: The current step fails with "Element not found" or the content script is destroyed by full navigation.
- Service worker: `chrome.webNavigation.onCommitted` fires. For SPA navigations, the content script detects via `popstate`/`pushState` interception.
- Daemon: Receives either an `action.result` with failure, a `tab.navigate`, or a `tab.disconnect` + `tab.connect` sequence.

**Behavior:**

SPA navigation (same document):
1. Content script remains alive.
2. Current step may fail if the target element was removed by the navigation.
3. Failure reported normally via `action.result`.

Full navigation (new document):
1. Content script is destroyed.
2. In-flight action never completes -- no `action.result` is sent.
3. Service worker sends `tab.disconnect` + `tab.connect` with updated URL.
4. Daemon disposes old TabScope (rejecting the pending action deferred with "Tab disconnected"), creates new TabScope.
5. The action is not retried automatically. The stream pipeline that originated the action receives the rejection and decides whether to retry based on the domain policy.

### 2.7 Disconnect During In-Flight Action

WebSocket drops while the content script is executing an `action.execute`.

**Behavior:**

Content script:
1. Continues executing the action to completion (it does not know the WebSocket dropped -- it communicates via the service worker's port, not directly via WebSocket).
2. Sends `action.result` to the service worker.
3. Service worker cannot forward to daemon (WebSocket is down). The result is buffered locally.
4. On reconnection, the buffered result is sent. The daemon has already rejected the pending deferred (see 2.1), so the late result is silently dropped (no matching deferred in `$pendingActions`).

Daemon:
1. Disposes TabScope, rejects the pending action deferred.
2. The action's effects on the page persist but the daemon has no record of the outcome.

**Consequence:** The content script may have successfully completed the action (e.g., archived an email), but the daemon does not know this. On reconnection, the daemon starts fresh with no knowledge of completed actions. The stream pipeline must be designed to tolerate this ambiguity -- re-reading the DOM after reconnection reveals the actual page state.

### 2.8 Extension Update or Reload

When the extension is updated or the developer reloads it, all extension contexts are destroyed and recreated.

**Detection:**
- Daemon: WebSocket closes.
- Extension: On restart, `chrome.runtime.onInstalled` fires with `reason: "update"`.

**Behavior:**

1. All content scripts die immediately.
2. Service worker restarts from scratch.
3. `chrome.storage.session` is cleared on any extension restart (update, dev reload, browser restart). Per Chrome documentation, session storage is memory-only and cleared whenever the extension process restarts for any reason.
4. `chrome.storage.local` persists across all restarts and updates.
5. Service worker re-bootstraps: reads daemon port via Native Messaging, opens WebSocket.
6. Previously connected tabs must be re-discovered. The extension stores connected tab IDs in `chrome.storage.local` as a backup. On restart, it checks which tabs still exist and re-injects content scripts.
7. Reconnection flow is identical for extension update, dev reload, and browser restart: restore from local backup, verify tabs, re-inject, reconnect.

**State lost:** All of 2.3, plus session storage (which is always cleared on restart -- the local backup is the recovery mechanism).

### 2.9 Multiple Extension Instances

Two Chrome profiles or two browsers connecting to the same daemon simultaneously.

**Behavior:**

The daemon accepts multiple WebSocket connections. Each gets its own `ExtensionSession`. TabIds are plain integers assigned by Chrome and can collide across different Chrome profiles/browsers.

The `sessionId` field in `tab.connect` (set to `chrome.runtime.id`) provides a per-extension-install identifier. The daemon uses `sessionId:tabId` as the composite key in `TabManager`, eliminating collisions. Single-browser-instance remains the primary supported configuration in v1, but multi-instance works correctly via the composite key.

---

## 3. State Reconciliation Protocol

After any disconnection (WebSocket drop, daemon restart, service worker restart), the extension and daemon must resynchronize. The protocol is deliberately simple: no session IDs, no replay buffers, no differential sync.

### 3.1 Design Decision: Fresh Start Over Replay

The reconnection protocol is a full resynchronization, not a replay of missed messages. Rationale:

1. The daemon's stream pipelines (filter, throttle, debounce, bufferWindow) maintain internal state that cannot be reconstructed from replayed raw events.
2. Replay requires sequence numbers, acknowledgment tracking, and bounded replay buffers on both sides -- complexity that buys little when the bottleneck is AI classification, not event throughput.
3. DOM snapshots after reconnection give the daemon a more accurate view of current page state than replaying stale mutation events would.
4. The system is designed around continuous observation, not transactional guarantees. Missing a few seconds of DOM mutations during a reconnection is acceptable.

### 3.2 Reconnection Sequence

```
Extension                              Daemon
    |                                      |
    |-- [WebSocket opened] --------------->|
    |                                      |  Creates ExtensionSession
    |                                      |
    |-- tab.connect {tabId:42, ...} ------>|  Creates TabScope
    |-- tab.connect {tabId:43, ...} ------>|  Creates TabScope
    |                                      |
    |-- dom.snapshot {tabId:42, ...} ----->|  Feeds into new stream pipeline
    |-- dom.snapshot {tabId:43, ...} ----->|  Feeds into new stream pipeline
    |                                      |
    |<- ui.update {status, tabId:42} ------|  Acknowledges connection
    |<- ui.update {status, tabId:43} ------|  Acknowledges connection
    |                                      |
    |  [Normal streaming resumes]          |
```

### 3.3 Extension Reconnection Steps

1. **Reconnect WebSocket.** Backoff: 1s, 2s, 4s, 8s, 16s, 30s cap, with 0-1s random jitter per attempt.
2. **Resend `tab.connect` for each active tab.** The extension reads connected tabs from `chrome.storage.session`, verifies each via `chrome.tabs.get()`, and sends `tab.connect` with current URL and title.
3. **Resend DOM snapshots.** For each reconnected tab, the content script sends a fresh `dom.snapshot` of the configured observation selectors. This gives the daemon current page state without replaying missed mutations.
4. **Flush buffered messages.** Content scripts that buffered messages during the disconnection flush their buffers. The daemon processes these as normal inbound events. Stale messages (mutations that are now superseded by the fresh snapshot) are harmless -- the stream pipeline's throttle/debounce operators smooth them out.

### 3.4 Daemon Reconnection Steps

The daemon is passive during reconnection -- it does not initiate anything. It processes incoming messages exactly as it would for a first connection:

1. **Accept WebSocket.** Create `ExtensionSession`.
2. **Process `tab.connect` messages.** Create `TabScope` for each. Load domain legos and policies from disk.
3. **Process `dom.snapshot` messages.** Feed into the new tab's stream pipeline. The classifier agent can begin working immediately.
4. **Send `ui.update` acknowledgments.** Report connection status, lego counts, and domain information to the side panel.

### 3.5 Content Script Resume

Content scripts that survived the disconnection (service worker restart or WebSocket-only drop):
- Their `MutationObserver` continued running throughout the disconnection.
- Messages generated during the gap were buffered locally (up to 64 messages, dropping oldest on overflow).
- On reconnection, the service worker re-establishes the port to the content script.
- The content script flushes its buffer and resumes normal forwarding.

Content scripts that were destroyed (tab navigation, extension update):
- The service worker re-injects the content script via `chrome.scripting.executeScript()`.
- The new content script starts fresh: sets up `MutationObserver`, sends `tab.connect` and initial `dom.snapshot`.

### 3.6 Side Panel Rebuild

The side panel is a React app that maintains its own state. On reconnection:
- The service worker sends `ui.update` messages with current status for each tab.
- The side panel replaces its state wholesale -- it does not attempt to merge with previous state.
- Conversation history in the side panel is preserved in the React component's local state (it is UI-only, not synced with the daemon).

---

## 4. Backpressure Propagation

The data flow path is: DOM -> Content Script -> Service Worker -> WebSocket -> Daemon Channel -> Stream Operators -> AI Agent. Backpressure must propagate backward through this entire chain. Without it, any stage that is slower than its upstream producer accumulates unbounded memory.

### 4.1 Pipeline Stages

```
[DOM MutationObserver]
        |
        | requestAnimationFrame batching (content script)
        v
[Content Script Buffer]  -- 64 messages, drop oldest on overflow
        |
        | chrome.runtime.Port
        v
[Service Worker]  -- forwards to WebSocket, no buffering
        |
        | WebSocket text frames
        v
[WsConnection.inbound Channel]  -- 32 items (phalanx-websocket default)
        |
        | BridgeGateway::pump() sequential processing
        v
[TabScope.inbound Channel]  -- 64 items
        |
        | Stream operators: filter -> throttle -> debounce -> bufferWindow
        v
[AI ClassifierAgent]  -- processes batches, 2-5 second cycle
        |
        | LegoExecutor
        v
[action.execute -> content script]  -- reverse path
```

### 4.2 Per-Stage Backpressure Mechanisms

**Stage 1: DOM -> Content Script**

The `MutationObserver` callback fires synchronously on every DOM change. The content script's observer callback batches mutations by collecting them into an array and scheduling a flush via `requestAnimationFrame`. This collapses rapid-fire mutations (e.g., React re-renders) into one batch per frame (~16ms at 60fps).

Backpressure: None from downstream. The content script always accepts mutations. If the outbound buffer is full, the oldest buffered messages are dropped (lossy). This is acceptable because a fresh `dom.snapshot` on reconnection supersedes any lost incremental mutations.

**Stage 2: Content Script -> Service Worker**

The content script sends messages via `chrome.runtime.Port`. Chrome's port messaging has no flow control -- messages are queued in Chrome's IPC layer.

Backpressure: None at the protocol level. The content script's local buffer (64 messages) is the only limit. When the content script detects its buffer exceeding 16 messages, it sends `flow.pressure` to the daemon (via the service worker). The daemon may respond with `flow.throttle`, which instructs the content script to increase its `requestAnimationFrame` batching window (e.g., batch every 4 frames instead of every frame) and reduce the fidelity of mutation summaries.

**Stage 3: Service Worker -> WebSocket**

The service worker forwards messages to the daemon's WebSocket without buffering. `WebSocket.send()` is non-blocking in the browser -- the browser's internal send buffer absorbs the data.

Backpressure: WebSocket flow control is handled by the TCP layer. If the daemon's TCP receive buffer fills, the browser's send buffer fills, and eventually `WebSocket.bufferedAmount` grows. The service worker monitors `bufferedAmount` after each send. If it exceeds 1MB, the service worker pauses forwarding and queues messages locally until `bufferedAmount` drops below 512KB.

**Stage 4: WebSocket -> Daemon Channel**

The `WsConnectionHandler` reads WebSocket frames and emits them into `WsConnection.inbound` (a `Channel` with bufferSize 32). The `BridgeGateway::pump()` loop consumes from this channel.

Backpressure: Channel hysteresis. When the inbound channel buffer reaches 32 items, the producer (WsConnectionHandler) suspends its fiber. This pauses reading from the TCP socket, which in turn fills the TCP receive buffer, which applies TCP-level backpressure to the sender. The producer resumes when the buffer drains to 50% (16 items).

**Stage 5: Daemon Channel -> Stream Operators**

`TabScope.inbound` is a Channel with bufferSize 64. The stream pipeline consumes from this channel via operators.

Backpressure: Same Channel hysteresis. If the stream operators (filter, throttle, debounce, bufferWindow) cannot keep up, the TabScope inbound channel fills. When full, `BridgeGateway::pump()` suspends on the `$tabScope->inbound->emit()` call, which cascades upstream to suspend the WebSocket read.

The `withPressure` callback on the Channel tracks pause/resume state. When the TabScope channel pauses the producer, the daemon sends `flow.throttle` to the extension for that tab. When it resumes (buffer at 50%), the daemon sends `flow.resume`.

**Stage 6: Stream Operators -> AI Agent**

The stream pipeline's `bufferWindow(count: 20, seconds: 2.0)` operator collects events into batches. Each batch is fed to the ClassifierAgent.

Backpressure: The ClassifierAgent runs as a fiber via `$scope->execute()`. While the AI call is in-flight, the stream pipeline's consume loop is suspended (it awaits the classification result before consuming the next batch). This naturally applies backpressure: if AI classification takes 3 seconds but events arrive every 2 seconds, the stream operators' buffers fill and Channel backpressure engages.

**Stage 7: AI Agent -> Action Execution**

The ClassifierAgent returns a list of lego invocations. The LegoExecutor sends `action.execute` commands and awaits `action.result` for each.

Backpressure: The executor runs sequentially (one action at a time per tab). The TabScope's `executeAction` method blocks the calling fiber until the content script responds. This means the AI -> action -> result cycle is inherently bounded: one action in flight per tab at a time.

### 4.3 Buffer Sizing Rationale

| Buffer | Size | Rationale |
|--------|------|-----------|
| Content script outbound | 64 messages | Handles 2-3 seconds of heavy DOM activity at 20-30 batches/sec. Lossy overflow is acceptable. |
| WsConnection.inbound | 32 items | Generic WebSocket default. Frames arrive from the transport layer; 32 gives headroom for burst without excessive memory. |
| TabScope.inbound | 64 items | Matches content script buffer. A full flush after reconnection delivers up to 64 messages -- the channel absorbs this without backpressure. |
| Service worker WebSocket bufferedAmount | 1MB pause / 512KB resume | Prevents the browser's send buffer from growing without bound. 1MB is well under Chrome's practical limit. |
| Stream bufferWindow | 20 items or 2 seconds | Balances AI batch efficiency (more context per call) against latency (user sees results within 2-5 seconds). |

### 4.4 When AI is Slower Than DOM

If the AI classification consistently takes longer than the `bufferWindow` interval:

1. The stream pipeline accumulates events in the bufferWindow operator.
2. When bufferWindow reaches its count limit (20), it emits a batch even though the previous batch's AI call may still be in-flight.
3. The consume loop is blocked awaiting the previous AI call, so the emitted batch sits in an internal buffer.
4. If this internal buffer grows, the Channel backpressure engages -- TabScope.inbound fills, WebSocket read suspends, TCP backpressure propagates to the extension.
5. The extension receives `flow.throttle` and reduces observation frequency.
6. The system reaches equilibrium: observation rate matches AI processing rate.

This is the correct behavior. The alternative -- dropping events silently -- would cause the AI to make classification decisions on incomplete data. Backpressure preserves data integrity by slowing the source rather than losing data at intermediate stages.

---

## 5. Build Sequencing Plan

The daemon and extension must be buildable and testable independently. Neither side should require the other to exist during development of core functionality.

### 5.1 Dependency Graph

```
D1: Daemon wire protocol (BridgeMessage, BridgeCommand)
D2: Daemon connection layer (BridgeGateway, ExtensionSession)  -- depends on D1
D3: Daemon tab lifecycle (TabManager, TabScope)                 -- depends on D2
D4: Daemon stream pipeline (Channel operators per tab)          -- depends on D3
D5: Daemon lego system (LegoLibrary, LegoExecutor)              -- depends on D3
D6: Daemon AI integration (Classifier, Generator, Repair)       -- depends on D4, D5
D7: Daemon flow control (throttle/resume logic)                 -- depends on D3, D4

E1: Extension service worker scaffold (WS connect, message routing)
E2: Extension Native Messaging bootstrap                        -- depends on E1
E3: Extension content script (DOM observer, action executor)    -- depends on E1
E4: Extension side panel UI (React, tab connector)              -- depends on E1
E5: Extension flow control (buffer, pressure reporting)         -- depends on E3
E6: Extension reconnection logic                                -- depends on E1, E3

I1: Integration: single tab connect/disconnect                  -- depends on D3, E1, E3
I2: Integration: DOM streaming + snapshot                       -- depends on D4, E3
I3: Integration: action execution round-trip                    -- depends on D5, E3
I4: Integration: flow control end-to-end                        -- depends on D7, E5
I5: Integration: reconnection + state reconciliation            -- depends on D3, E6
I6: Integration: AI classification + action cycle               -- depends on D6, I2, I3
```

### 5.2 Daemon-Only Milestones (Testable with wscat or custom WS client)

**D1: Wire protocol types (week 1)**

Build `BridgeMessage::fromJson()` and `BridgeCommand::toJson()` with full test coverage. No I/O, no event loop. Pure data transformation.

Test: Unit tests. Round-trip every message type through serialize/deserialize.

**D2: Connection layer (week 1)**

`BridgeGateway` accepts a WebSocket connection. Decodes JSON frames into `BridgeMessage`. Routes by type prefix. Logs unknown types.

Test with wscat:
```
wscat -c ws://localhost:9078/bridge
> {"type":"tab.connect","tabId":1,"url":"https://example.com","title":"Test","domain":"example.com"}
```
Verify daemon logs show tab connection.

**D3: Tab lifecycle (week 2)**

`TabManager` creates/destroys `TabScope` on connect/disconnect. Verify scope disposal cancels child fibers. Verify session cleanup disposes all owned tabs.

Test: Send `tab.connect`, verify TabScope exists. Send `tab.disconnect`, verify TabScope is disposed. Close WebSocket, verify all tabs for that session are disposed.

**D4: Stream pipeline (week 2-3)**

Wire `TabScope.inbound` channel through stream operators. Verify filter, throttle, debounce, bufferWindow produce expected output batches.

Test: Send a sequence of `dom.mutations` messages via wscat. Verify the stream pipeline emits batched, filtered output. Mock the AI agent to capture what the pipeline produces.

**D5: Lego system (week 2-3)**

`LegoLibrary` file I/O, `LegoExecutor` sends `action.execute` and correlates `action.result`.

Test: Send `action.result` messages via wscat in response to `action.execute` frames received from the daemon. Verify round-trip correlation.

**D6: AI integration (week 4)**

Wire `ClassifierAgent`, `GeneratorAgent`, `RepairAgent` with mock AI providers. Verify the classification -> lego invocation -> action execution pipeline.

Test: Mock the AI provider to return predetermined classifications. Verify the daemon sends the correct `action.execute` commands.

**D7: Flow control (week 3)**

Verify `flow.pressure` triggers `flow.throttle` when Channel buffer depth crosses threshold. Verify `flow.resume` is sent when buffer drains.

Test: Send rapid-fire messages via wscat to fill the TabScope channel. Verify daemon sends `flow.throttle`. Slow down sends. Verify daemon sends `flow.resume`.

### 5.3 Extension-Only Milestones (Testable with mock WebSocket server)

A mock WebSocket server is a ~50 line Node.js script that accepts connections, logs received messages, and sends predetermined responses. It must support:
- Accepting connections on a configurable port.
- Logging all received messages to stdout as JSON.
- Sending messages from a script or interactive prompt.
- Simulating close/error conditions.

**E1: Service worker scaffold (week 1)**

WebSocket connection to mock server. Message send/receive. `chrome.storage.session` state persistence.

Test: Connect to mock server. Send a `tab.connect` message. Verify mock server receives it. Receive a `ui.update` from mock server. Verify service worker forwards to side panel.

**E2: Native Messaging bootstrap (week 1)**

Read daemon port from lockfile via Native Messaging host script. Fall back to hardcoded port if Native Messaging unavailable.

Test: Create a test lockfile with a known port. Verify the extension discovers it and connects to the correct port.

**E3: Content script (week 2-3)**

`MutationObserver` setup, mutation batching, action step executor (all 16 ops), message forwarding to service worker.

Test: Load a test page. Inject content script. Verify `dom.snapshot` is sent on injection. Modify the page DOM. Verify `dom.mutations` batches are sent. Send `action.execute` from mock server. Verify the content script executes the steps and returns `action.result`.

**E4: Side panel UI (week 2-3)**

React components for tab connector, confidence display, conversation interface. Receives `ui.update` messages from service worker.

Test: Send mock `ui.update` messages. Verify UI renders correctly. No daemon required.

**E5: Flow control (week 3)**

Content script buffer depth tracking, `flow.pressure` emission, `flow.throttle` response (increased debounce interval).

Test: Fill the content script buffer by suspending the service worker's port. Verify `flow.pressure` is sent. Send `flow.throttle` from mock server. Verify the content script reduces observation frequency.

**E6: Reconnection (week 4)**

Service worker detects disconnection, persists state, reconnects with backoff, re-sends `tab.connect` and `dom.snapshot` for each active tab.

Test: Connect to mock server. Register tabs. Kill mock server. Verify reconnection attempts with backoff. Restart mock server. Verify extension re-sends `tab.connect` messages for all previously connected tabs.

### 5.4 Integration Milestones

**I1: Single tab lifecycle (week 3)**

Extension connects to real daemon. `tab.connect` -> TabScope created. `tab.disconnect` -> TabScope disposed. Close tab -> same. Close WebSocket -> all tabs disposed.

**I2: DOM streaming (week 4)**

Content script observes real page mutations. Daemon receives through stream pipeline. Verify batching, throttling, and buffering work end-to-end.

**I3: Action round-trip (week 4)**

Daemon sends `action.execute`. Content script executes steps on real page. `action.result` returns to daemon. Verify for each of the 16 ops.

**I4: Flow control end-to-end (week 5)**

Generate heavy DOM mutation load on a test page. Verify backpressure propagates: content script reports pressure, daemon throttles, content script reduces frequency, daemon resumes.

**I5: Reconnection (week 5)**

Kill daemon mid-stream. Restart. Verify extension reconnects, resends tab state, daemon rebuilds TabScopes, streaming resumes.

**I6: AI cycle (week 6)**

Full loop: DOM snapshot -> classifier -> lego selection -> action execution -> result. Requires real or mocked AI provider.

### 5.5 Suggested Build Order

Start both sides in parallel. The daemon team and extension team can work independently for weeks 1-3 using wscat and mock servers respectively. Integration begins in week 3 with a simple connection test.

```
Week 1:  D1 + D2          |  E1 + E2
Week 2:  D3 + D4 + D5     |  E3 + E4
Week 3:  D7 + I1           |  E5
Week 4:  D6 + I2 + I3     |  E6
Week 5:  I4 + I5           |  (extension polish)
Week 6:  I6                |  (extension polish)
```

---

## 6. Testing Boundary

### 6.1 Unit Testable Per Side (No Integration Required)

**Daemon unit tests:**
- `BridgeMessage::fromJson` -- all message types, missing fields, malformed input.
- `BridgeCommand` -- serialization for every static factory, round-trip fidelity.
- `LegoDefinition` -- confidence computation, immutable builders, JSON round-trip.
- `DomainPolicy` -- user action logging, override tracking, rule updates, log truncation at 500.
- `PolicyRule` -- serialization, threshold computation.
- `LegoLibrary` -- file system round-trips, domain isolation, path sanitization.
- `PolicyStore` -- file system round-trips, domain lookup.

**Extension unit tests:**
- Message serialization/deserialization (TypeScript types matching this spec).
- Action step executor -- each of the 16 ops against a jsdom or happy-dom environment.
- Mutation summarizer -- given raw MutationRecords, produces the expected summary format.
- Buffer management -- verify drop-oldest behavior at capacity, flush behavior.
- Reconnection state machine -- verify backoff timing, state persistence/restore.
- Side panel components -- React component tests with mock `ui.update` data.

### 6.2 Integration Testable Per Side (With Mock Counterpart)

**Daemon with mock WebSocket client:**
- `BridgeGateway` message routing -- send various message types, verify they reach the correct TabManager handler.
- `TabManager` lifecycle -- connect/disconnect sequences, multi-session management, session cleanup.
- `TabScope` action correlation -- send `action.execute`, respond with `action.result`, verify deferred resolution.
- `TabScope` disposal -- verify all pending deferreds rejected, channels completed, cancellation propagated.
- Stream pipeline -- send a message sequence, verify operator chain output.
- Flow control -- fill Channel, verify throttle sent, drain, verify resume sent.

**Extension with mock WebSocket server:**
- Service worker connection lifecycle -- connect, message send/receive, reconnection on close.
- Content script message flow -- inject into test page, verify messages forwarded to mock server.
- Action execution -- mock server sends `action.execute`, verify content script executes and returns `action.result`.
- Native Messaging bootstrap -- verify port discovery from lockfile.
- Reconnection -- verify state persistence to `chrome.storage.session`, reconnection with backoff, tab re-registration.

### 6.3 Requires Full Integration

These scenarios cannot be meaningfully tested with mocks because the behavior depends on interactions between real components:

- **Backpressure propagation end-to-end:** TCP backpressure from a slow daemon Channel must visibly reduce the extension's send rate. Mock servers process messages instantly and never apply TCP backpressure.
- **Navigation race conditions:** Real Chrome navigations destroy content scripts at unpredictable times. Mock environments cannot reproduce the timing.
- **Service worker termination:** Chrome's service worker lifecycle is not reproducible outside a real browser. Automated testing requires `chrome.debugger` or a test framework like Playwright that can control Chrome.
- **Multi-tab stream correlation:** The daemon's concurrent fiber management under real load from multiple content scripts cannot be simulated by sequential mock sends.
- **AI classification latency impact:** Real AI provider latency determines the backpressure equilibrium point. Mock providers respond instantly.

### 6.4 Mock Fidelity Requirements

**Mock WebSocket server (for extension testing) must:**
- Support configurable message delays (simulating slow daemon).
- Support abrupt close (simulating daemon crash -- close without close frame).
- Support clean close (simulating intentional shutdown -- close with 1000 code).
- Log all received messages with timestamps for assertion.
- Support scripted response sequences (e.g., "on receiving tab.connect, respond with ui.update after 50ms").

**Mock WebSocket client (for daemon testing) must:**
- Support sending arbitrary JSON messages.
- Support receiving and asserting on daemon responses.
- Support simulating connection drop (close without close frame).
- Support concurrent connections (multiple clients to test multi-session).
- Support timing assertions ("daemon sent flow.throttle within 500ms of Channel filling").

**Mock AI provider (for daemon AI testing) must:**
- Accept the same input format as real providers (system prompt + user message).
- Return predetermined classification results.
- Support configurable latency (simulating real AI response times for backpressure testing).
- Record all invocations for assertion (verify the classifier received the expected DOM data).
