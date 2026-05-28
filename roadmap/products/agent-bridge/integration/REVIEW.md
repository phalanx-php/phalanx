# Integration Review -- Protocol Mismatches, Failure Modes, and Gaps

Reviewer: bridge-integration-architect
Date: 2026-04-04
Specs reviewed:
- `integration/SPEC.md` (wire protocol, failure catalog, backpressure, build plan)
- `daemon/SPEC.md` (process lifecycle, service registration, stream pipeline, AI tools, failure handling)
- `extension/SPEC.md` (content script, service worker, side panel, Plasmo structure)

---

## Issue 1: `user.chat` Is an Unregistered Protocol Message

**Severity: CRITICAL**

**Specs affected:** extension/SPEC.md Section 5 (send-chat.ts, line ~1618), integration/SPEC.md Section 1.1

**Discrepancy:**

The extension's `send-chat.ts` handler sends `{ type: "user.chat", tabId, text }` to the daemon via WebSocket. This message type does not exist in the integration spec's wire protocol (Section 1.1 defines Extension-to-Daemon messages; `user.chat` is absent). The `ExtensionMessage` union type in `extension/SPEC.md` Section 2 also omits `user.chat` -- the code uses `as any` to bypass TypeScript's type checking. The daemon's `BridgeGateway` routes messages by type prefix. There is no handler for `user.chat` in the daemon spec. The message is silently ignored per the protocol evolution rule ("both sides must silently ignore unknown type values"), meaning user chat messages are dropped on the floor.

**Impact:** The AgentConversation UI in the side panel lets users type messages that are never processed. The user-facing chat feature is non-functional.

**Recommended fix:** Either (a) add `user.chat` to the integration spec Section 1.1 with fields `tabId: int`, `text: string`, add a `UserChat` interface to the TypeScript types, and add a daemon handler that routes the message to the appropriate agent (GeneratorAgent for new lego requests, or a ConversationAgent), or (b) remove the chat input from the side panel UI and defer to v2.

---

## Issue 2: `dom.response` Routing Mismatch -- `requestId` vs `actionId`

**Severity: CRITICAL**

**Specs affected:** daemon/SPEC.md Section 3 (stream pipeline), integration/SPEC.md Section 1.1

**Discrepancy:**

The daemon's `TabManager::handleDomMessage()` emits ALL `dom.*` messages into `TabScope::$inbound` channel, feeding the stream pipeline. This includes `dom.response`, which is a reply to a `dom.request` and should resolve a pending Deferred in `$pendingActions` (or a parallel `$pendingDomRequests` map).

The `dom.response` message carries `requestId` (format `dreq_{n}`, per integration spec). The daemon's `handleActionResult` method (daemon spec Section 5.4, `TabScope::executeAction`) looks for results in `$pendingActions` keyed by `actionId`. The `dom.response` has no `actionId` field. The `action.result` has no `requestId` field. These are two different correlation mechanisms for two different request types.

The daemon spec shows `TabScope::queryDom()` (used by `ValidateSelector` tool, Section 4.4) which sends a `dom.request` and presumably awaits a response. But there is no code showing how `dom.response` resolves the Deferred created by `queryDom()`. If `dom.response` messages go into the inbound channel and through the stream pipeline, they are filtered out by the pipeline's filter (which only passes `dom.snapshot`, `dom.mutations`, `net.response`). The Deferred is never resolved. `queryDom()` hangs until the action timeout fires.

**Impact:** `ValidateSelector` tool (used by GeneratorAgent during lego creation) will hang on every call. `requestRepair()` (daemon spec Section 5.7) calls `$tab->queryDom('body', limit: 500)` -- this also hangs. The entire lego generation and repair pipeline is broken.

**Recommended fix:** Route `dom.response` messages separately from the stream pipeline. In `TabManager`, before emitting into `TabScope::$inbound`, check for `type === 'dom.response'` and resolve the corresponding Deferred in a dedicated `$pendingDomRequests` map keyed by `requestId`. This parallels the `$pendingActions` pattern for `action.result`.

---

## Issue 3: Full Cross-Origin Navigation Does Not Send `tab.disconnect`

**Severity: CRITICAL**

**Specs affected:** extension/SPEC.md Section 4 (handleNavigation, line ~1406; handlePortConnect, line ~1226), integration/SPEC.md Section 2.4, Section 2.6

**Discrepancy:**

The integration spec (Section 2.4, 2.6) states that on full navigation, the extension sends `tab.disconnect` followed by `tab.connect` with the same tabId. The extension spec's `handleNavigation()` (line ~1406) is a no-op -- it contains a comment saying "The content script handles sending tab.disconnect + tab.connect on reconnect" but does nothing.

The actual disconnect detection is in `handlePortConnect`'s `port.onDisconnect` handler (line ~1226). When the content script's port disconnects (which happens on full navigation because the content script is destroyed), the handler calls `chrome.tabs.get(tabId).catch(...)`. On full cross-origin navigation, the tab STILL EXISTS -- it navigated to a new URL. `chrome.tabs.get(tabId)` succeeds, so the `.catch()` branch never executes. No `tab.disconnect` is sent.

The old content script is dead. The new page has no content script injected (the declarative content script runs at `document_idle` on all URLs, but only sets up a listener -- it does nothing until the service worker opens a port, which never happens because the service worker doesn't know to reconnect).

**Impact:** The daemon retains a stale `TabScope` for the navigated tab. No new `TabScope` is created for the new page. The stale TabScope holds dead state (old URL, old domain, old legos). Events stop flowing for that tab. The tab appears stuck in its pre-navigation state from the daemon's perspective.

**Recommended fix:** In the `port.onDisconnect` handler, when `chrome.tabs.get(tabId)` SUCCEEDS (tab still exists), send `tab.disconnect` for the old context, then re-inject the content script and open a new port to trigger a fresh `tab.connect`. Alternatively, handle this in `handleNavigation()` by proactively sending `tab.disconnect` and re-injecting the content script when `webNavigation.onCommitted` fires for a connected tab.

---

## Issue 4: `__resend_snapshot` Has No Content Script Handler

**Severity: HIGH**

**Specs affected:** extension/SPEC.md Section 4 (reconnection, line ~1491), extension/SPEC.md Section 3 (content script)

**Discrepancy:**

The service worker's `handleWsOpen()` reconnection logic (line ~1491) sends `{ type: "__resend_snapshot" }` to content scripts via port. The content script's message handler (`handleDaemonMessage`) dispatches on `msg.type` for `action.execute`, `action.cancel`, `dom.request`, `flow.throttle`, and `flow.resume`. There is no case for `__resend_snapshot`.

The content script's `handleDaemonMessage` is the port's `onMessage` listener. Since `__resend_snapshot` matches no case, the message is silently ignored. The content script never sends a fresh `dom.snapshot` after reconnection.

**Impact:** After WebSocket reconnection, the daemon receives `tab.connect` messages but no `dom.snapshot`. The stream pipeline starts but has no initial data. The classifier has no DOM context. The system reconnects but is functionally inert until new DOM mutations happen to trigger a `dom.mutations` message.

**Recommended fix:** Add a handler for `__resend_snapshot` in the content script's message dispatch:

```typescript
case "__resend_snapshot":
  sendInitialSnapshot()
  break
```

Or rename to a protocol-consistent name like `dom.resnapshot` and add it to the integration spec's Daemon-to-Extension messages.

---

## Issue 5: `bodyPreview` Always Absent in v1 -- Pipeline Implication

**Severity: MEDIUM**

**Specs affected:** integration/SPEC.md Section 1.1 (net.response), extension/SPEC.md Section 4 (network-observer.ts, line ~1311), daemon/SPEC.md Section 3.4 (extractDomElements)

**Discrepancy:**

The integration spec defines `bodyPreview` as optional on `net.response`. The extension spec (line ~1311) explicitly states MV3 `chrome.webRequest` cannot capture response bodies and that v1 sends `net.response` without `bodyPreview`. This is consistent.

However, the daemon's stream pipeline filter (daemon spec Section 3.2) passes `net.response` through to the classifier. The `extractDomElements` method (daemon spec Section 3.4) extracts `url`, `status`, and `contentType` from network events but not `bodyPreview`. So the daemon handles the absence correctly in code.

The gap is documentation: the integration spec does not state that `bodyPreview` is always absent in v1. A future implementer reading only the integration spec might design daemon features that depend on `bodyPreview` availability.

**Recommended fix:** Add a note to the integration spec's `net.response` definition: "v1 note: `bodyPreview` is never populated. The extension's MV3 `webRequest` API does not expose response bodies. Body capture requires MAIN world fetch/XHR interception (planned for v2)."

---

## Issue 6: `throttleThreshold` and `resumeThreshold` Config Values Are Dead

**Severity: HIGH**

**Specs affected:** daemon/SPEC.md Section 2.3 (BridgeConfig), Section 3.7 (backpressure integration), integration/SPEC.md Section 4.2

**Discrepancy:**

`BridgeConfig` defines `throttleThreshold: 48` and `resumeThreshold: 32`. These are populated from environment variables `BRIDGE_THROTTLE_THRESHOLD` and `BRIDGE_RESUME_THRESHOLD` and documented in the configuration reference (daemon spec Section 6).

The `withPressure` callback in Section 3.7 fires when the Channel buffer reaches capacity (64) for pause and 50% (32) for resume -- these are Channel-level hysteresis thresholds baked into the Channel implementation, not the config values. The `$config` variable is resolved in the `withPressure` closure's scope but never referenced. The config values 48 and 32 are completely unused.

The integration spec (Section 4.2, Stage 5) says "When the TabScope channel pauses the producer, the daemon sends `flow.throttle`." This happens at Channel capacity (64), not at the configured throttleThreshold (48).

**Impact:** Configuration knobs for throttle/resume thresholds have no effect. Users/operators adjusting these values will see no behavioral change. The 48/32 values imply throttling should engage earlier than Channel full (at 75% capacity), which would be a better design, but the implementation doesn't honor them.

**Recommended fix:** Either (a) wire the config values into the Channel by using a custom pressure callback that fires at the configured thresholds rather than at Channel hysteresis boundaries, or (b) remove `throttleThreshold` and `resumeThreshold` from `BridgeConfig` and the environment variable list, and document that throttle/resume fires at Channel capacity/50% as a fixed behavior.

---

## Issue 7: `flow.throttle maxEventsPerSec=0` Destroys Observer State

**Severity: HIGH**

**Specs affected:** extension/SPEC.md Section 3 (throttle response, line ~899), integration/SPEC.md Section 1.2 (flow.throttle)

**Discrepancy:**

The integration spec defines `maxEventsPerSec=0` as "pause all non-essential events." The extension's `handleFlowThrottle` (line ~899) implements this by calling `observer?.disconnect()`, which detaches the MutationObserver from the DOM entirely. When `flow.resume` arrives, `handleFlowResume` calls `startObserving()`, which creates a brand new MutationObserver.

Between disconnect and reconnect, all DOM mutations are lost. The new observer has no record of what changed. Unlike the WebSocket drop case (where a fresh `dom.snapshot` is sent), there is no snapshot trigger after `flow.resume`. The daemon resumes receiving mutation events but has a gap in its observation window with no mechanism to fill it.

**Impact:** After a throttle-to-zero/resume cycle, the daemon's view of the page may be stale. Mutations that occurred during the pause are invisible. The classifier may make decisions based on an outdated DOM model. This is particularly problematic for pages with continuous updates (dashboards, feeds).

**Recommended fix:** After `startObserving()` in `handleFlowResume`, immediately send a fresh `dom.snapshot` to bring the daemon up to date:

```typescript
function handleFlowResume(): void {
  throttleFrameSkip = 1
  if (!observer) {
    startObserving()
    sendInitialSnapshot() // Fill the observation gap
  }
}
```

---

## Issue 8: `waitForNetwork` Cross-Context Function Undefined

**Severity: HIGH**

**Specs affected:** extension/SPEC.md Section 3 (action step executor, line ~661)

**Discrepancy:**

The `waitForNetwork` op calls `waitForNetworkViaServiceWorker(step.urlPattern, step.timeoutMs ?? 10000)`. This function is called in the content script but never defined anywhere in the extension spec. The content script runs in the page's isolated world and has no access to `chrome.webRequest`.

The integration spec (Section 1.3) says `waitForNetwork` "requires cooperation with the service worker's network listener." The extension spec has a comment at line ~659 saying "Requires cooperation with service worker. Send request via port, service worker responds when matching network event completes." But no implementation is shown for either side:

1. The content script has no code to send a "watch for network event" request to the service worker.
2. The service worker's `network-observer.ts` has no handler for such requests.
3. There is no defined message type for this cross-context operation.

**Impact:** Any lego step using `waitForNetwork` will throw a ReferenceError (`waitForNetworkViaServiceWorker is not defined`) and fail the entire action sequence.

**Recommended fix:** Define the cross-context protocol:

1. Content script sends a port message: `{ type: "__wait_network", urlPattern: string, nonce: string }`.
2. Service worker registers the pattern against incoming `chrome.webRequest.onCompleted` events.
3. When a matching request completes, the service worker sends `{ type: "__network_matched", nonce: string }` back via the port.
4. The content script resolves the promise.
5. Timeout is handled by the content script via `Promise.race` with a timer.

Add the message types and handler implementations to both the extension spec Section 3 and Section 4.

---

## Issue 9: `evaluate` Op Runs in Isolated World, Not Page Context

**Severity: HIGH**

**Specs affected:** extension/SPEC.md Section 3 (line ~674-679), integration/SPEC.md Section 1.3

**Discrepancy:**

The integration spec defines `evaluate` as: "Execute arbitrary JS expression in the page context, return serialized result." The extension spec implements it using `eval(step.expression)` inside the content script. The content script runs in Chrome's ISOLATED world (explicitly stated in extension spec Section 3, paragraph 1: "It runs in Chrome's CONTENT (isolated) world... It cannot access `window` objects set by the page").

The extension spec even acknowledges this at line ~675: "Execute in ISOLATED world. For page-context access, the daemon must use a separate MAIN world script (not implemented in v1)."

This means:
- `evaluate("document.title")` works (DOM access is shared).
- `evaluate("window.myApp.state")` fails (`window.myApp` is undefined in isolated world).
- `evaluate("React")` fails (page-injected globals are invisible).

The integration spec's description ("page context") implies access to page-level JavaScript, which the implementation cannot provide.

**Impact:** Any lego or AI-generated action that uses `evaluate` to read page-level JavaScript state (framework state, global variables, SPA router state) will silently return `undefined` or throw. The daemon and AI agents have no way to know this limitation unless it's documented.

**Recommended fix:** Update the integration spec's `evaluate` description to: "Execute arbitrary JS expression in the content script's isolated world. Has full DOM access but cannot access page-context JavaScript globals (`window.*` set by the page). For page-context evaluation, use a MAIN world injection (not available in v1)." Add this to the classifier and generator agent system prompts so the AI does not generate `evaluate` steps that depend on page globals.

---

## Issue 10: `removeConnectedTab` Signature Mismatch

**Severity: MEDIUM**

**Specs affected:** extension/SPEC.md Section 4 (line ~1229, ~1360)

**Discrepancy:**

`removeConnectedTab` is defined in `state.ts` (line ~1360) with signature `removeConnectedTab(tabId: number): Promise<void>` (one parameter). However, in the service worker's `handlePortConnect` (line ~1229), it's called as `removeConnectedTab(tabId)` without a second argument, which is fine. But earlier in `initialize()` (line ~1009), it's called as `removeConnectedTab(tabId, state)` with two arguments.

The function loads state internally via `loadState()` -- it does not accept a `state` parameter. The second argument is silently ignored in JavaScript, so this is not a runtime error, but it indicates a design confusion: some callers pass state expecting the function to use it (avoiding the redundant `loadState()` call), while the function always loads fresh state.

**Impact:** Minor inefficiency (double `loadState()` calls) and code confusion. Not a functional bug.

**Recommended fix:** Decide whether `removeConnectedTab` should accept an optional `state` parameter to avoid reloading, or consistently call it with one argument. Update all call sites to match.

---

## Issue 11: Service Worker `setTimeout` for Reconnection Is Fragile

**Severity: MEDIUM**

**Specs affected:** extension/SPEC.md Section 4 (ws-manager.ts, line ~1169), Section 4 MV3 constraints

**Discrepancy:**

The extension spec's MV3 constraints section states: "No `setTimeout`/`setInterval` for scheduling. Timers are cancelled on termination. Use `chrome.alarms` for anything beyond immediate use."

The `scheduleReconnect` function (line ~1169) uses `setTimeout(() => connect(url), delayMs)` for reconnection. The spec includes a comment acknowledging this: "Use setTimeout for short reconnect delays (destroyed if SW terminates, but chrome.alarms has a 30-second minimum which is too slow for initial retries)."

For the first few attempts (1s, 2s, 4s delays), `setTimeout` is the only option since `chrome.alarms` minimum is 30 seconds. But for later attempts (8s, 16s, 30s), `chrome.alarms` should be used because Chrome may terminate the service worker during these longer waits, losing the scheduled reconnection entirely.

**Impact:** If Chrome terminates the service worker during a reconnection backoff window longer than ~5 seconds, the reconnection timer is lost. The service worker will only reconnect on the next external event (tab update, alarm, etc.). The native messaging keepalive mitigates this, but if the native port also disconnected, the service worker could remain dormant.

**Recommended fix:** Use `setTimeout` for backoff delays under 5 seconds (attempts 0-2). Switch to `chrome.alarms` for delays of 5 seconds or more. On alarm fire, read the persisted `wsUrl` from state and attempt reconnection.

---

## Issue 12: Side Panel `chrome.tabs` API May Not Be Available

**Severity: MEDIUM**

**Specs affected:** extension/SPEC.md Section 6 (TabConnector component, line ~1753)

**Discrepancy:**

The `TabConnector` component directly calls `chrome.tabs.query()` and registers `chrome.tabs.onUpdated/onRemoved/onCreated` listeners inside a React component. The side panel runs as a browser page (similar to popup), where `chrome.tabs` API is available.

However, the component also registers `chrome.tabs.onUpdated` as a listener with a callback that calls `chrome.tabs.query()` on every tab update event. This fires for ALL tabs in the browser (not just connected ones), triggering a full `chrome.tabs.query()` on every favicon change, loading status change, and title update across all windows.

**Impact:** Performance degradation on browsers with many tabs. Each `chrome.tabs.onUpdated` event triggers a full tab query, causing unnecessary re-renders. Not a correctness bug, but will cause visible UI lag with 50+ tabs.

**Recommended fix:** Filter `onUpdated` events before querying. Only re-query when `changeInfo` contains `status`, `url`, or `title` changes. Debounce the query with a short timeout (100-200ms) to collapse rapid-fire updates.

---

## Issue 13: Content Script `handleDaemonMessage` Dispatch Missing

**Severity: MEDIUM**

**Specs affected:** extension/SPEC.md Section 3

**Discrepancy:**

The content script registers `port.onMessage.addListener(handleDaemonMessage)` (line ~368) but the `handleDaemonMessage` function is never shown. Individual handler functions exist (`handleDomRequest`, `handleFlowThrottle`, `handleFlowResume`, and the `executeAction` function), but no dispatcher connects incoming messages to these handlers. The content script also needs to dispatch `action.cancel` messages to set the `cancelledActions` set referenced in `executeAction`.

**Impact:** This is likely an editorial omission rather than a design gap, but the spec is incomplete without it. An implementer must infer the dispatch logic.

**Recommended fix:** Add the dispatcher function to the content script section:

```typescript
function handleDaemonMessage(msg: DaemonMessage): void {
  switch (msg.type) {
    case "action.execute": executeAction(msg); break
    case "action.cancel": cancelledActions.add(msg.actionId); break
    case "dom.request": handleDomRequest(msg); break
    case "flow.throttle": handleFlowThrottle(msg); break
    case "flow.resume": handleFlowResume(); break
  }
}
```

---

## Issue 14: `action.result` for Cancelled Action Never Cleans Up `cancelledActions` Set

**Severity: LOW**

**Specs affected:** extension/SPEC.md Section 3 (action step executor)

**Discrepancy:**

When `action.cancel` arrives, the content script adds the `actionId` to a `cancelledActions` Set. The `executeAction` function checks this set before each step. When cancellation is detected, `sendResult` is called and the function returns. But the `actionId` is never removed from the `cancelledActions` set.

Over a long-lived content script session, the set grows unboundedly. Each cancelled action adds an entry that is never cleaned up.

**Impact:** Marginal memory leak. In practice, action IDs are short strings and cancellations are infrequent, so this is unlikely to cause real problems. But it is a correctness issue.

**Recommended fix:** Delete from `cancelledActions` after sending the cancel result:

```typescript
if (cancelledActions.has(msg.actionId)) {
  cancelledActions.delete(msg.actionId)
  sendResult(msg, false, undefined, "cancelled")
  return
}
```

---

## Issue 15: Port `onDisconnect` Sends `tab.disconnect` Only on Tab Close

**Severity: Duplicate of Issue 3 -- documenting the second manifestation**

**Specs affected:** extension/SPEC.md Section 4 (router.ts, line ~1226)

The `port.onDisconnect` handler in `handlePortConnect`:

```typescript
port.onDisconnect.addListener(() => {
  tabPorts.delete(tabId)
  chrome.tabs.get(tabId).catch(() => {
    sendToDaemon({ type: "tab.disconnect", tabId })
    removeConnectedTab(tabId)
  })
})
```

This sends `tab.disconnect` ONLY when `chrome.tabs.get()` fails (tab doesn't exist). For all other port disconnect causes (navigation, extension update, content script crash), no `tab.disconnect` is sent if the tab still exists. This is the root cause described in Issue 3 but worth noting as a code-level detail.

---

## Issue 16: `action.cancel` Forwarded to Content Script But Port May Not Exist

**Severity: LOW**

**Specs affected:** extension/SPEC.md Section 4 (router.ts, line ~1197)

**Discrepancy:**

The daemon sends `action.cancel` for a specific `tabId`. The router looks up `tabPorts.get(msg.tabId)` and calls `port?.postMessage(msg)`. If the tab's port disconnected (content script died), `tabPorts` no longer has the entry, and `port?.postMessage` does nothing. The `action.cancel` is silently lost.

This is actually correct behavior (the action can't be executing if the content script is dead), but it means the daemon's `action.cancel` for a dead tab never gets a response. The daemon's pending Deferred for that action is only resolved by timeout or the session disposal cascade from `tab.disconnect`.

**Impact:** The daemon must always handle the case where `action.cancel` gets no response. The timeout mechanism (daemon spec Section 5.4) covers this, but it means a 30-second wait for an action on a dead content script. The faster path is via `tab.disconnect` -> TabScope disposal -> Deferred rejection, but per Issue 3, `tab.disconnect` may not be sent.

**Recommended fix:** Addressed by fixing Issue 3. Once `tab.disconnect` is reliably sent for all content script deaths, the TabScope disposal rejects the pending Deferred immediately.

---

## Issue 17: Reconnection Sends Stale Metadata from `chrome.storage.session`

**Severity: MEDIUM**

**Specs affected:** extension/SPEC.md Section 4 (reconnection, line ~1473), integration/SPEC.md Section 3.3

**Discrepancy:**

On WebSocket reconnection, `handleWsOpen()` resends `tab.connect` using metadata from `state.tabMeta[tabId]` -- which was persisted at the time of original connection or last `handleTabUpdated`. If the tab navigated (SPA or full) while the WebSocket was down, the persisted URL and title are stale.

The integration spec's reconnection protocol (Section 3.3, step 2) says: "The extension reads connected tabs from `chrome.storage.session`, verifies each via `chrome.tabs.get()`, and sends `tab.connect` with current URL and title." The implementation verifies the tab exists (`await chrome.tabs.get(tabId)`) but does not use the returned tab object's `url` and `title` -- it uses the stale `meta` from storage.

**Impact:** The daemon creates a `TabScope` with an incorrect URL and domain. Domain-specific legos and policies are loaded for the wrong domain. The classifier operates on mismatched context.

**Recommended fix:** Use the tab object returned by `chrome.tabs.get()`:

```typescript
const tab = await chrome.tabs.get(tabId)
const domain = new URL(tab.url!).hostname
sendToDaemon({
  type: "tab.connect",
  tabId,
  url: tab.url!,
  title: tab.title ?? "",
  domain
})
```

---

## Issue 18: `handleWsClose` Mutates State Object Directly

**Severity: LOW**

**Specs affected:** extension/SPEC.md Section 4 (reconnection, line ~1456)

**Discrepancy:**

`handleWsClose(state: BridgeState)` receives the state object by reference and mutates it directly: `state.daemonStatus = "reconnecting"`. This object was loaded via `loadState()` in `initialize()` at service worker startup. Any other code path that loaded state separately has a stale copy.

Since `persistState` is called immediately after mutation, the storage is correct. But the in-memory `state` object passed around through callbacks (`onClose`, `onMessage`, `onOpen`) is a single mutable reference shared across all callbacks, which is fragile.

**Impact:** If any async operation reads state between `handleWsClose` mutating `state` and calling `persistState`, the intermediate state is only visible to code holding the same reference. This is not currently a bug but is a latent race condition.

**Recommended fix:** Always reload state from storage rather than mutating a passed reference: `const state = await loadState(); state.daemonStatus = "reconnecting"; await persistState(state)`.

---

## Issue 19: No Daemon-to-Extension Acknowledgment for `tab.connect`

**Severity: MEDIUM**

**Specs affected:** integration/SPEC.md Section 3.2, extension/SPEC.md Section 4

**Discrepancy:**

The reconnection sequence diagram (integration spec Section 3.2) shows the daemon sending `ui.update {status, tabId}` as an acknowledgment after `tab.connect`. But this is described as an "acknowledgment" only in the diagram commentary. There is no formal handshake. The extension does not wait for this acknowledgment before sending `dom.snapshot` or resuming normal streaming.

If the daemon fails to process `tab.connect` (e.g., malformed message, internal error), the extension proceeds to stream data for a tab the daemon never registered. All subsequent messages for that tab are dropped (no matching TabScope).

**Impact:** Silent data loss on `tab.connect` failure. The extension believes the tab is connected. The daemon does not.

**Recommended fix:** This is a documentation gap more than a design bug (the "fire and forget" approach is an explicit design choice per Section 3.1). Document that `tab.connect` failures are detected indirectly: if no `ui.update status` arrives within 5 seconds, the extension should retry `tab.connect`. Add this retry logic to the extension spec.

---

## Issue 20: `extractDomElements` Accesses `msg.url` -- Field Does Not Exist on `BridgeMessage`

**Severity: MEDIUM**

**Specs affected:** daemon/SPEC.md Section 3.4 (extractDomElements)

**Discrepancy:**

In `extractDomElements`, the `net.response` branch accesses `$msg->payload['url'] ?? $msg->url ?? ''`. The `BridgeMessage` type is created from `fromJson()` on the raw wire message. The `net.response` wire message (integration spec Section 1.1) has fields `tabId`, `requestId`, `status`, `contentType`, `bodyPreview?`, `durationMs`, `timestamp`. There is no `url` field on `net.response`.

The `net.request` message has `url`, but `net.response` does not (it references `net.request` by `requestId`). The daemon would need to correlate the `requestId` back to the original `net.request` to get the URL, but the stream pipeline only passes `net.response` (filter drops `net.request`).

**Impact:** The `url` field in extracted network elements will always be empty string. The classifier receives network events without URL context, reducing classification quality.

**Recommended fix:** Either (a) add `url` to the `net.response` wire message in the integration spec (extension already has access to the URL in the `pendingRequests` map at send time), or (b) maintain a `requestId -> url` lookup in the daemon's `TabScope` populated from `net.request` messages, and enrich `net.response` messages before passing to the stream pipeline.

---

## Issue 21: `dom.snapshot` Selector Scope Not Configurable Per Tab

**Severity: LOW**

**Specs affected:** extension/SPEC.md Section 3, Section 8 (constants)

**Discrepancy:**

The extension defines `DEFAULT_SNAPSHOT_SELECTOR = "body"` as a constant. The content script's `sendInitialSnapshot()` presumably uses this selector. But the integration spec says `dom.snapshot` contains a `selector` field indicating "Scoped HTML snapshot of a DOM subtree. Never full-page HTML."

The daemon has no mechanism to tell the extension which selector to observe. The content script always observes `document.body`. The `selector` field in `dom.snapshot` reports what was observed, but the daemon cannot change it.

**Impact:** Low -- `body` is a reasonable default. But for large pages, observing the full body produces large snapshots (the WebSocket max message size is 4MB per daemon spec Section 1.1). There is no mechanism to scope observation to a smaller subtree.

**Recommended fix:** Add a daemon-to-extension message `dom.configure` with fields `tabId: int`, `selector: string` that instructs the content script to change its observation root. Defer to v2 if not immediately needed.

---

## Issue 22: `webRequest.onCompleted` Requires `responseHeaders` Extra Info Spec

**Severity: LOW**

**Specs affected:** extension/SPEC.md Section 4 (network-observer.ts, line ~1299)

**Discrepancy:**

The `chrome.webRequest.onCompleted` listener is registered with `["responseHeaders"]` as the extra info spec. In MV3, accessing `responseHeaders` from `webRequest.onCompleted` requires `"webRequest"` permission AND the listener must include `"responseHeaders"` in the `extraInfoSpec` array. The extension has `"webRequest"` in its permissions.

However, in MV3, `extraInfoSpec: ["responseHeaders"]` on `onCompleted` is only available if the extension also declares `"webRequestAuthProvider"` permission or uses `"extraHeaders"`. Without `"extraHeaders"`, some headers (notably `Set-Cookie`) are not accessible. The `Content-Type` header is generally available without `"extraHeaders"`.

**Impact:** Minor. `Content-Type` is accessible without extra permissions. But if future versions need other headers, the permission gap will surface.

**Recommended fix:** Document that `responseHeaders` access in MV3 is limited without `"extraHeaders"` in `extraInfoSpec`. Add `"extraHeaders"` only if needed in future versions (it triggers additional Chrome Web Store review scrutiny).

---

## Summary by Severity

| Severity | Count | Issues |
|----------|-------|--------|
| CRITICAL | 3 | #1 (user.chat), #2 (dom.response routing), #3 (navigation disconnect) |
| HIGH | 4 | #4 (__resend_snapshot), #6 (dead config), #7 (observer state loss), #8 (waitForNetwork), #9 (evaluate world) |
| MEDIUM | 6 | #5 (bodyPreview docs), #10 (signature), #11 (setTimeout), #12 (tab query perf), #17 (stale metadata), #19 (no ack), #20 (missing url) |
| LOW | 4 | #13 (missing dispatcher), #14 (cancelledActions leak), #16 (cancel to dead port), #18 (state mutation), #21 (selector scope), #22 (responseHeaders) |

The three CRITICAL issues (#1, #2, #3) each break a core feature: user chat is non-functional, DOM queries hang forever, and cross-origin navigation leaves ghost tabs. These must be resolved before implementation begins.
