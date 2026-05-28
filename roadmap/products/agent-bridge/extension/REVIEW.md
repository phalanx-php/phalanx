# Extension Spec Review -- Platform Reality, Integration Alignment, and Missing Implementation

Reviewer: plasmo-extension-expert
Date: 2026-04-04
Specs reviewed:
- `extension/SPEC.md` (content script, service worker, side panel, Plasmo structure)
- `daemon/SPEC.md` (process lifecycle, stream pipeline, AI tools, failure handling)
- `integration/SPEC.md` (wire protocol, failure catalog, backpressure, state reconciliation)

Reference material:
- Plasmo framework deep dive (file conventions, messaging, build output, CSUI lifecycle)
- Chrome Extensions API reference (MV3 patterns, storage, webRequest, sidePanel, alarms, tabs)
- Nightlight extension (real-world Plasmo project for build output verification)

Note: The integration/REVIEW.md and daemon/REVIEW.md already cover `user.chat` (unregistered protocol message), `dom.response` routing (requestId vs actionId), and full cross-origin navigation disconnect gaps. This review does not duplicate those findings. It focuses on Chrome platform behavior, Plasmo framework specifics, and implementation gaps within the extension boundary.

---

## Section 1: Platform Reality Check

### 1.1 `chrome.tabs.connect()` Port Direction -- Works, But Creates a Race

**Severity: HIGH**

**Spec reference:** Extension SPEC Section 5, connect-tab.ts (line ~1541)

**Claim:** The service worker calls `chrome.tabs.connect(tabId, { name: "tab-bridge" })` to open a port to the content script, and the content script listens via `chrome.runtime.onConnect`.

**Reality:** `chrome.tabs.connect(tabId)` does work from the service worker to initiate a port connection to a content script. The content script's `chrome.runtime.onConnect` listener fires. This is the documented API behavior per Chrome's message passing documentation.

**Problem:** The spec has a race condition between injection and connection. In `connect-tab.ts`, the service worker calls `chrome.scripting.executeScript()` to inject the content script and then immediately calls `chrome.tabs.connect()`. `executeScript` is async -- it returns a Promise. But even after the Promise resolves, the content script's top-level code (including registering `chrome.runtime.onConnect`) may not have executed yet. The content script runs at `document_idle`, but when injected programmatically via `executeScript`, it runs as soon as the script is evaluated. There is no guarantee the `onConnect` listener is registered before the `connect()` call arrives.

If the `connect()` call arrives before the content script registers its listener, the port opens on the service worker side with no receiver. The service worker holds a `Port` object that appears valid but has no content script counterpart. Messages sent through it are silently dropped. The `onDisconnect` fires eventually (when Chrome detects no listener), but there is no retry logic.

**Evidence:** The spec's `connect-tab.ts` does `await chrome.scripting.executeScript(...)` then immediately `chrome.tabs.connect(tabId, ...)`. No delay, no handshake, no retry.

**Additional concern:** The port returned by `chrome.tabs.connect()` in `connect-tab.ts` is never stored in the `tabPorts` Map. The `handlePortConnect` function in `router.ts` (Section 4) populates `tabPorts` when it receives an inbound `chrome.runtime.onConnect` event. But `connect-tab.ts` initiates the connection from the service worker side -- the resulting port does not trigger `onConnect` on the service worker. The service worker's `onConnect` listener only fires for ports initiated by content scripts (via `chrome.runtime.connect()`). So `connect-tab.ts` creates a port that is never tracked, and `tabPorts` is never populated for this tab through this code path.

**Design conflict:** The spec describes two contradictory port establishment patterns:
1. Section 3 (Content Script Lifecycle, step 3): "Waits for service worker to open port" -- implies service worker initiates via `chrome.tabs.connect()`
2. Section 4 (router.ts `handlePortConnect`): Listens for `chrome.runtime.onConnect` with `port.sender?.tab?.id` -- this only fires when the content script initiates via `chrome.runtime.connect()`

These are mutually exclusive. If the service worker opens the port, the content script's `onConnect` fires. If the content script opens the port, the service worker's `onConnect` fires. The spec uses both patterns simultaneously for the same connection, which cannot work.

---

### 1.2 `chrome.webRequest.onCompleted` with `responseHeaders` -- Works in MV3 Read-Only Mode

**Severity: OK (no issue)**

**Spec reference:** Extension SPEC Section 4, network-observer.ts (line ~1279-1299)

**Claim:** The network observer calls `chrome.webRequest.onCompleted.addListener(callback, { urls: ["<all_urls>"] }, ["responseHeaders"])` to get response headers.

**Reality:** This works in MV3. The `webRequest` API in MV3 lost blocking/modification capabilities but retained read-only observation. The `["responseHeaders"]` extraInfoSpec parameter populates `details.responseHeaders` in the callback. The extension correctly requests `"webRequest"` permission and `<all_urls>` host permission. No issue here.

---

### 1.3 `chrome.storage.session` Clearing Behavior -- Spec Claim Is Wrong

**Severity: MEDIUM**

**Spec reference:** Extension SPEC Section 4 (state.ts commentary, line ~1376), Integration SPEC Section 2.8 (line ~327)

**Claim:** "`chrome.storage.session` is cleared on extension update (NOT on dev reload)."

**Reality:** Per Chrome's documentation, `chrome.storage.session` holds data in memory and is cleared when the extension is "disabled, reloaded, updated, and when the browser restarts." Dev reload IS a reload -- session storage IS cleared on dev reload. The spec's claim that session storage survives dev reload is incorrect.

**Impact:** The integration spec's Section 2.8 states "For dev reload: `chrome.storage.session` survives. Same reconnection flow as service worker restart (2.3)." This is wrong. On dev reload, session storage is cleared, `connectedTabs` is lost, and the extension falls back to `chrome.storage.local` backup -- same as a production update. The distinction the spec draws between dev reload and production update does not exist for session storage.

The `handleStartup` function already handles the recovery path (restore from local backup), but the spec's reasoning about when each path triggers is incorrect. The practical impact is small because the local backup exists, but the spec's mental model is misleading for implementers.

---

### 1.4 `chrome.sidePanel.setPanelBehavior()` -- Returns a Promise, Not Synchronous

**Severity: LOW**

**Spec reference:** Extension SPEC Section 4, background/index.ts (line ~963)

**Claim:** Called at the top level synchronously:
```typescript
chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true })
```

**Reality:** `chrome.sidePanel.setPanelBehavior()` returns `Promise<void>`. Calling it without `await` at the top level is not "synchronous" -- it fires and forgets. This is fine for a fire-and-forget configuration call (the behavior will be set before the user can interact), but the spec should not describe it as synchronous. The unhandled Promise rejection risk is real if the call fails (e.g., `sidePanel` permission missing).

The call IS correctly placed at the top level (not behind an async gate), which is good for service worker lifecycle. The issue is terminology, not behavior.

---

### 1.5 `chrome.alarms` Requires Explicit Permission Declaration

**Severity: HIGH**

**Spec reference:** Extension SPEC Section 7 (line ~1878)

**Claim:** The "Permissions NOT Requested" table states: "`alarms` -- Implicitly available. Does not require explicit permission declaration."

**Reality:** The Chrome alarms API documentation states: "To use the chrome.alarms API, declare the `alarms` permission in the manifest." The `alarms` permission is listed in Chrome's permissions reference as a required declaration. It does not trigger a user-facing warning (it is a low-risk permission), but it must be declared in the manifest.

**Impact:** The extension uses `chrome.alarms.create()` in `native-bootstrap.ts` (line ~1050: `chrome.alarms.create("native-reconnect", ...)`) and `chrome.alarms.onAlarm.addListener()` in `background/index.ts` (line ~976). Without the `alarms` permission in the manifest, these calls will throw at runtime.

The `package.json` manifest override (line ~90-99) does not include `"alarms"` in the permissions array. This must be added.

---

### 1.6 `attributeFilter` Does Not Support Wildcards

**Severity: HIGH**

**Spec reference:** Extension SPEC Section 3, MutationObserver setup (line ~412)

**Claim:** The MutationObserver config uses:
```typescript
attributeFilter: ["class", "data-*", "aria-*", "href", "src", "value", "checked", "disabled", "hidden"]
```

**Reality:** The `attributeFilter` option in `MutationObserver.observe()` takes an array of exact attribute name strings. There is no wildcard or glob support. `"data-*"` is interpreted as a literal attribute name -- it will only match an attribute literally named `data-*`, which does not exist in practice. Same for `"aria-*"`.

Per the MDN specification, `attributeFilter` is "An array of specific attribute names to be monitored. If this property isn't included, changes to all attributes cause mutation notifications."

**Impact:** The MutationObserver will miss ALL `data-*` attribute changes (e.g., `data-testid`, `data-id`, `data-state`, `data-selected`) and ALL `aria-*` attribute changes (e.g., `aria-expanded`, `aria-selected`, `aria-hidden`). These are exactly the attributes the spec considers "semantically meaningful" (Section 3 commentary, line ~419). The observer will only catch changes to the 7 literal attributes: `class`, `href`, `src`, `value`, `checked`, `disabled`, `hidden`.

**Fix options:**
1. List every specific `data-*` and `aria-*` attribute of interest: `["class", "data-testid", "data-id", "data-state", "data-selected", "data-value", "aria-expanded", "aria-selected", "aria-hidden", "aria-label", "aria-checked", "aria-disabled", "href", "src", "value", "checked", "disabled", "hidden"]`
2. Remove `attributeFilter` entirely and set `attributes: true` to catch all attribute changes, then filter in the MutationObserver callback. This increases noise but guarantees no misses. Given the mutation summarizer already filters for visibility and deduplicates, this is the safer option.

---

## Section 2: Integration Alignment

### 2.1 `user.chat` -- Extension Sends, Daemon Has No Handler

**Severity: CRITICAL** (already documented in integration/REVIEW.md Issue 1 and daemon/REVIEW.md Section 2.2)

Extension SPEC Section 5 (`send-chat.ts`, line ~1614-1618) sends `{ type: "user.chat", tabId, text }` with an `as any` type cast. This type is not in the integration spec's wire protocol, not in the `ExtensionMessage` union, and the daemon has no handler for it. The message is silently dropped per the protocol evolution rule.

Not re-analyzing here -- see sibling reviews for full details.

---

### 2.2 `__resend_snapshot` -- Service Worker Sends, Content Script Has No Handler

**Severity: HIGH** (already documented in integration/REVIEW.md Issue 4)

Extension SPEC Section 4 (`handleWsOpen`, line ~1491) sends `{ type: "__resend_snapshot" }` to content scripts via port. The content script's `handleDaemonMessage` function (referenced in Section 3 but never shown in full) dispatches on `msg.type` for `action.execute`, `action.cancel`, `dom.request`, `flow.throttle`, and `flow.resume`. No case for `__resend_snapshot`.

The internal message is silently ignored. State reconciliation after reconnection (integration SPEC Section 3.3, step 3: "resend DOM snapshots") never happens. The daemon reconnects but never receives fresh snapshots.

---

### 2.3 Full Cross-Origin Navigation -- `tab.disconnect` Not Sent

**Severity: CRITICAL** (already documented in integration/REVIEW.md Issue 3)

The full trace through the extension code:

1. User navigates to different origin. Content script is destroyed.
2. Content script's port disconnects. Service worker's `port.onDisconnect` fires in `handlePortConnect` (line ~1225-1232).
3. The disconnect handler checks if the tab still exists: `chrome.tabs.get(tabId).catch(...)`.
4. **The tab DOES exist** -- it navigated, it was not closed. The `.catch()` does not fire.
5. The `.then()` path (implicit: `chrome.tabs.get` resolves successfully) has no handler. It resolves and does nothing.
6. `tab.disconnect` is never sent to the daemon.
7. The daemon's TabScope for this tab persists indefinitely with stale state.

The `handlePortConnect`'s disconnect handler only sends `tab.disconnect` inside the `.catch()` block (tab does not exist). For navigation-caused disconnects where the tab still exists, no disconnect message is sent. The `handleNavigation` function (line ~1406-1413) has a comment saying "The content script handles sending tab.disconnect + tab.connect on reconnect" -- but the content script is dead at this point.

---

### 2.4 `sendToDaemon` Import Scope Across Background Files

**Severity: MEDIUM**

**Spec reference:** Extension SPEC Section 5 (message handlers), Section 4 (ws-manager.ts)

**Problem:** The Plasmo message handlers in `background/messages/connect-tab.ts`, `disconnect-tab.ts`, and `send-chat.ts` all call `sendToDaemon()`. This function is exported from `~lib/ws-manager.ts`. The handlers also reference `tabPorts`, `addConnectedTab`, `removeConnectedTab`, `loadState` -- functions and state from `~lib/router.ts` and `~lib/state.ts`.

In Plasmo, each file in `background/messages/` is a self-contained module. The service worker entry point (`background/index.ts`) is a separate module. All background modules run in the same service worker JavaScript context, so module-level state (like `let ws: WebSocket | null = null` in `ws-manager.ts`) is shared as long as they import from the same module.

This works IF:
1. The message handler modules import the same singleton module instances.
2. The WebSocket has been initialized (via `initWebSocket()` called from `background/index.ts`) before any message handler fires.

Condition 2 is the risk. If the service worker restarts and a Plasmo message arrives before `initialize()` completes (which includes `bootstrapNativeMessaging()` -- a 5-second timeout), `sendToDaemon()` will queue the message into `sendQueue`. This is handled correctly by the queue. But `tabPorts` (a `Map` in `router.ts`) will be empty -- the port connections have not been re-established. The message handler references `tabPorts.get(tabId)` which returns `undefined`.

The `disconnect-tab.ts` handler calls `tabPorts.get(tabId)?.disconnect()` -- the optional chaining handles the undefined case safely. But `connect-tab.ts` creates a new port via `chrome.tabs.connect()` without checking if the old port exists, potentially creating duplicate port references.

**Key concern:** The spec never shows the import statements for the message handler files. The code references `sendToDaemon`, `tabPorts`, `addConnectedTab`, `removeConnectedTab`, `loadState` as if they are ambient globals. They must be explicit imports from shared modules. The spec should show these imports to prevent ambiguity during implementation.

---

### 2.5 Content Script Injection Path -- `contents/bridge.js` May Be Wrong

**Severity: HIGH**

**Spec reference:** Extension SPEC Section 3, programmatic injection (line ~333-336)

**Claim:**
```typescript
await chrome.scripting.executeScript({
  target: { tabId },
  files: ["contents/bridge.js"]
})
```

**Reality:** Plasmo's build output path for content scripts is not simply `contents/{filename}.js`. Plasmo uses Parcel internally and the output filenames include content hashes in production builds. Even in dev builds, the exact output path depends on Plasmo's internal build pipeline.

From the Plasmo documentation: "You may also use the `files` key to inject a file from the root of the built bundle." The key phrase is "from the root of the built bundle." Plasmo's generated manifest includes the correct content script paths, but these paths are determined at build time.

Examining a real Plasmo project (Nightlight), the `.plasmo/static/background/index.ts` imports from `"../../../src/background"`, confirming that Plasmo rewrites paths during build. The final output in `build/chrome-mv3-dev/` or `build/chrome-mv3-prod/` uses Plasmo-determined filenames.

The hardcoded `"contents/bridge.js"` path will likely fail because:
1. Plasmo may hash the filename (e.g., `contents/bridge.a1b2c3d4.js`)
2. Plasmo may restructure the path differently from the source directory
3. Plasmo content scripts declared via `PlasmoCSConfig` are automatically registered in the manifest -- programmatic re-injection of the same script may conflict with the declarative injection

**Alternative approach:** Instead of `chrome.scripting.executeScript` with a hardcoded file path, the service worker should send a message to an already-injected content script (which Plasmo auto-injects via the manifest `content_scripts` entry) to activate. Or, use `chrome.scripting.executeScript` with a `func` parameter instead of `files`, passing a bootstrap function that communicates with the already-running content script.

---

### 2.6 Network Observer `bodyPreview` Gap

**Severity: LOW (documented limitation)**

**Spec reference:** Extension SPEC Section 4, network-observer.ts (line ~1311)

The integration spec defines `bodyPreview: string?` on `net.response` (Section 1.1). The extension spec explicitly acknowledges this is not available from `chrome.webRequest` and defers to v2. This is correctly called out and not an oversight. However, the daemon's stream pipeline may expect `bodyPreview` for classification. The integration spec does not mark `bodyPreview` as "always absent in v1" -- the daemon code should handle `undefined` gracefully.

---

## Section 3: Missing Implementation

### 3.1 `waitForNetworkViaServiceWorker()` -- Called But Never Defined

**Severity: HIGH**

**Spec reference:** Extension SPEC Section 3, action step executor (line ~661)

The `waitForNetwork` op calls:
```typescript
await waitForNetworkViaServiceWorker(step.urlPattern, step.timeoutMs ?? 10000)
```

This function is never defined anywhere in the spec. The `waitForNetwork` op requires coordination between the content script and the service worker because `chrome.webRequest` events are only available in the service worker context, not in content scripts.

**Required implementation:**
1. Content script sends a request to the service worker via port: `{ type: "__waitForNetwork", urlPattern, requestId }`
2. Service worker registers a listener on `chrome.webRequest.onCompleted` for URLs matching the glob pattern
3. When a matching request completes, service worker sends response via port: `{ type: "__networkComplete", requestId }`
4. Content script resolves the Promise
5. Timeout handled by the content script with `Promise.race()`

This requires:
- A new internal message type (`__waitForNetwork` / `__networkComplete`)
- Port message routing in the service worker for this internal protocol
- Glob-to-regex conversion for the `urlPattern` matching
- Cleanup of the webRequest listener after match or timeout

None of this infrastructure exists in the spec.

---

### 3.2 `isConnectedTab()` -- Called But Never Defined

**Severity: MEDIUM**

**Spec reference:** Extension SPEC Section 4, network-observer.ts (line ~1258), handleTabUpdated (line ~1391), handleNavigation (line ~1408)

Called in three places:
```typescript
if (!isConnectedTab(details.tabId)) return  // network-observer.ts
if (!isConnectedTab(tabId)) return          // handleTabUpdated
if (!isConnectedTab(details.tabId)) return  // handleNavigation
```

Never defined. Likely implementation: check `tabPorts.has(tabId)` or read from stored state. But `tabPorts` lives in `router.ts` and the callers are in `network-observer.ts` and `background/index.ts`. The function needs to either:
- Be exported from `router.ts` (creates cross-module coupling for a state check)
- Read from `chrome.storage.session` (async, cannot be used synchronously in event handlers)
- Use a module-level Set that mirrors `tabPorts` keys (requires syncing two data structures)

The simplest correct implementation is `tabPorts.has(tabId)`, exported from `router.ts`.

---

### 3.3 `currentTabId` -- Used But Never Initialized

**Severity: MEDIUM**

**Spec reference:** Extension SPEC Section 3, content script (lines ~398, 721, 730, 741, 752, 762, 779, 826)

`currentTabId` is used throughout the content script for every outbound message (`dom.mutations`, `user.action`, `dom.response`, `flow.pressure`). It is never shown how this value is obtained.

Content scripts do not have direct access to their own tab ID. The value must come from one of:
1. The service worker sends the `tabId` when opening the port connection (via a message after port opens)
2. `chrome.runtime.sendMessage()` can get `sender.tab.id` in the response, but this is a round-trip
3. The `chrome.tabs.connect()` call from the service worker does not automatically pass the tab ID to the content script

The most reliable pattern: when the port connects, the service worker immediately sends `{ type: "__init", tabId }` as the first message. The content script stores this value. But this handshake is not described in the spec.

---

### 3.4 `handleDaemonMessage` -- Referenced But Routing Not Shown

**Severity: MEDIUM**

**Spec reference:** Extension SPEC Section 3, port communication (line ~367)

```typescript
port.onMessage.addListener(handleDaemonMessage)
```

The `handleDaemonMessage` function is referenced but its dispatch logic is never shown. From context, it must handle:
- `action.execute` -> `executeAction()`
- `action.cancel` -> add to `cancelledActions` Set
- `dom.request` -> `handleDomRequest()`
- `flow.throttle` -> `handleFlowThrottle()`
- `flow.resume` -> `handleFlowResume()`
- `__resend_snapshot` -> (missing, see Section 2.2)
- `__init` -> (missing, see Section 3.3 for tabId initialization)
- `__waitForNetwork` response -> (missing, see Section 3.1)

Without the dispatch function defined, the content script has no entry point for daemon commands.

---

## Section 4: Plasmo-Specific Issues

### 4.1 Port Handler File Conflicts with Raw `onConnect`

**Severity: HIGH**

**Spec reference:** Extension SPEC Section 5 (background/ports/tab-bridge.ts, line ~1628-1641), Section 4 (handlePortConnect in router.ts, line ~1213)

**Problem:** The spec creates `background/ports/tab-bridge.ts` to satisfy Plasmo's file-based port routing convention, while also registering a raw `chrome.runtime.onConnect` listener in `background/index.ts`.

Plasmo's port system works by intercepting `chrome.runtime.onConnect` events and routing them to the appropriate handler file based on the port name. When a port named `"tab-bridge"` connects, Plasmo will route it to `background/ports/tab-bridge.ts`. Simultaneously, the raw `chrome.runtime.onConnect.addListener(handlePortConnect)` in `background/index.ts` will also fire for the same connection event.

This means every `"tab-bridge"` port connection triggers TWO handlers:
1. Plasmo's internal router calls the exported handler in `tab-bridge.ts`
2. The raw `handlePortConnect` function in `background/index.ts`

The Plasmo handler is a no-op (`// Port-level messages are handled by the onConnect listener in background/index.ts`), so the functional impact may be minimal. But Plasmo's port abstraction wraps the raw port in its own message handling. The raw handler and Plasmo's handler both attach `onMessage` listeners to the same port -- Plasmo's listener expects messages in its own format (`{ body, name }` envelope), while the raw handler expects bare `ExtensionMessage` objects.

**Risk:** Plasmo may consume or transform port messages before the raw handler sees them. The exact behavior depends on Plasmo's internal implementation of port routing, which is not documented. At minimum, this creates unpredictable double-handling.

**Resolution options:**
1. Remove the `background/ports/tab-bridge.ts` file entirely and rely solely on the raw `chrome.runtime.onConnect` handler. Plasmo does not require port handler files to exist -- they are an opt-in convention.
2. Move all port handling into the Plasmo port handler and remove the raw `onConnect` listener. This requires adapting the message format to Plasmo's port protocol.
3. Use a different port name that does not match any Plasmo port file, avoiding the routing conflict entirely.

---

### 4.2 Content Script Build Output Path

**Severity: HIGH** (overlaps with Section 2.5)

**Spec reference:** Extension SPEC Section 1 (manifest generation table, line ~58-60)

Plasmo's file convention for content scripts: `src/contents/bridge.ts` generates a `content_scripts` manifest entry. The built output path in the manifest is determined by Plasmo's build pipeline and includes the source-relative path plus potential hashing.

For declarative content script injection (via manifest `content_scripts` array), this is automatic -- Plasmo handles the path. The problem arises only with programmatic injection via `chrome.scripting.executeScript({ files: ["contents/bridge.js"] })` where the path is hardcoded.

Plasmo does not guarantee the output path `contents/bridge.js`. The actual output may be:
- `contents/bridge.js` (dev, no hash)
- `contents/bridge.HASH.js` (prod, content hash)
- A completely different path structure

The spec should use `chrome.scripting.executeScript` with a `func` parameter or `chrome.tabs.sendMessage` to the already-auto-injected content script rather than re-injecting via `files`.

---

### 4.3 `~*` Path Alias Configuration

**Severity: OK (no issue)**

**Spec reference:** Extension SPEC Section 1, tsconfig.json (line ~112-122)

```json
{
  "paths": { "~*": ["./src/*"] },
  "baseUrl": "."
}
```

This is correct per Plasmo's documented convention for `src/` directory projects. Plasmo's `tsconfig.base` supports the tilde alias. Imports like `"~lib/ws-manager"` resolve to `./src/lib/ws-manager`. The `baseUrl: "."` is required for path resolution. No issue.

---

### 4.4 `eval()` in Content Script Isolated World

**Severity: MEDIUM**

**Spec reference:** Extension SPEC Section 3, `evaluate` op (line ~677-679)

```typescript
case "evaluate": {
  const result = eval(step.expression)
  return { result }
}
```

**Problem:** MV3 Content Security Policy restricts `eval()` in extension contexts. The content script runs in the CONTENT (isolated) world, which is an extension context. The default MV3 CSP is `script-src 'self' 'wasm-unsafe-eval'` -- `eval()` is not allowed.

The spec acknowledges this partially (line ~675: "Execute in ISOLATED world. For page-context access, the daemon must use a separate MAIN world script") but does not address the CSP restriction. The `eval()` call will throw a `EvalError` at runtime.

**Workaround options:**
1. Use `chrome.scripting.executeScript` from the service worker with `world: "MAIN"` and a `func` parameter for page-context evaluation
2. Use a sandbox page with relaxed CSP and communicate via `postMessage`
3. Relax the extension's CSP in the manifest (adds store review scrutiny)
4. Use `new Function()` instead of `eval()` -- also blocked by default CSP in MV3

The integration spec lists `evaluate` as an "escape hatch" (line ~114). Without a working `eval()`, this escape hatch is bricked.

---

### 4.5 Side Panel `chrome.tabs.query()` and `chrome.tabs.onUpdated` Access

**Severity: LOW**

**Spec reference:** Extension SPEC Section 6, TabConnector component (line ~1753)

The side panel component directly calls `chrome.tabs.query()` and registers `chrome.tabs.onUpdated` listeners. Side panels are extension pages and have access to `chrome.tabs` with the `tabs` permission (which is declared). This works.

However, the pattern of registering `chrome.tabs.onUpdated.addListener` inside a React `useEffect` creates a new listener on every mount. If the side panel re-renders the `TabConnector` component (e.g., state change causes unmount/remount), multiple listeners accumulate. The cleanup function removes listeners correctly via the return callback, so this is handled. No issue.

---

## Summary

### Issue Severity Matrix

| Severity | Count | Issues |
|----------|-------|--------|
| CRITICAL | 1 (new) + 2 (cross-ref) | #2.3 (navigation disconnect -- cross-ref), #2.1 (user.chat -- cross-ref), #1.1 (port direction race + missing tracking) |
| HIGH | 5 | #1.5 (alarms permission), #1.6 (attributeFilter wildcards), #2.5 (content script path), #3.1 (waitForNetworkViaServiceWorker), #4.1 (port handler conflict) |
| MEDIUM | 5 | #1.3 (session storage clearing), #2.4 (import scope), #3.2 (isConnectedTab), #3.3 (currentTabId), #4.4 (eval CSP) |
| LOW | 2 | #1.4 (setPanelBehavior async), #2.6 (bodyPreview gap) |

### Top 5 Issues to Fix Before Implementation

1. **attributeFilter wildcards** (#1.6) -- The MutationObserver silently misses all `data-*` and `aria-*` attribute changes. These are the exact attributes the system depends on for stable selectors. Remove `attributeFilter` or enumerate specific attributes.

2. **Port establishment race and tracking gap** (#1.1) -- `connect-tab.ts` creates a port that is never stored in `tabPorts`, and the two port patterns (service-worker-initiated vs content-script-initiated) conflict. Settle on one pattern. Recommended: content script initiates the port after injection; service worker listens via `onConnect`.

3. **alarms permission missing** (#1.5) -- Runtime failure. Add `"alarms"` to the manifest permissions array.

4. **Content script injection path** (#2.5 / #4.2) -- Hardcoded `"contents/bridge.js"` will not match Plasmo's build output. Redesign the injection strategy.

5. **Plasmo port handler conflict** (#4.1) -- Remove `background/ports/tab-bridge.ts` or remove the raw `onConnect` listener. Do not use both.
