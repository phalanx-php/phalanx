# Phalanx Agent Bridge -- Extension Spec

Chrome extension built with Plasmo framework (Manifest V3). This document covers everything inside the extension boundary. The wire protocol, failure modes, backpressure propagation, and state reconciliation are defined in `../integration/SPEC.md` -- this spec references those contracts and specifies their implementation.

Proprietary product. Not open source.

---

## 1. Plasmo Project Structure

### Directory Layout

```
extension/
├── assets/
│   └── icon.png                          # 512x512 source, Plasmo auto-generates sizes
├── src/
│   ├── sidepanel.tsx                     # Side panel entry (generates side_panel manifest)
│   ├── background/
│   │   ├── index.ts                      # Service worker entry (generates background.service_worker)
│   │   ├── messages/
│   │   │   ├── connect-tab.ts            # Side panel requests tab connection
│   │   │   ├── disconnect-tab.ts         # Side panel requests tab disconnection
│   │   │   ├── get-state.ts              # Side panel requests current state snapshot
│   │   │   └── send-chat.ts             # Side panel sends user message to daemon
│   │   # No ports/ directory -- tab-bridge port handled by raw onConnect in index.ts
│   ├── contents/
│   │   └── bridge.ts                     # Content script entry: imports and wires modules
│   ├── lib/
│   │   ├── types.ts                      # Wire protocol TypeScript types
│   │   ├── dom-observer.ts               # MutationObserver, summarizer, selector generator
│   │   ├── dom-responder.ts              # dom.request handler: getAttribute, getTextContent, evaluate
│   │   ├── action-executor.ts            # 16 ops, executeAction, executeStep, actionInProgress flag
│   │   ├── user-observer.ts              # click/type/select/scroll/submit listeners, actionInProgress guard
│   │   ├── ws-manager.ts                 # WebSocket lifecycle, reconnection, bufferedAmount
│   │   ├── native-bootstrap.ts           # connectNative() port discovery
│   │   ├── state.ts                      # chrome.storage.session persistence
│   │   ├── router.ts                     # Daemon message routing to content scripts / side panel
│   │   ├── network-observer.ts           # chrome.webRequest listener setup
│   │   └── constants.ts                  # Buffer sizes, timeouts, backoff config
│   └── components/
│       ├── TabConnector.tsx              # Available tabs list, connect/disconnect controls
│       ├── ConfidenceDisplay.tsx         # Per-action confidence meters
│       ├── AgentConversation.tsx         # Chat-like agent message feed
│       ├── StatusBar.tsx                 # Connection state, daemon online/offline
│       └── TabBadge.tsx                  # Individual tab status indicator
├── package.json                          # Plasmo config, manifest overrides, dependencies
├── tsconfig.json                         # Strict mode, src/ path aliases
├── .env                                  # PLASMO_PUBLIC_* build-time vars
└── SPEC.md                               # This file
```

### Manifest Generation

Plasmo generates the manifest from file conventions and `package.json` overrides. No raw `manifest.json` file.

| File | Generated Manifest Entry |
|------|--------------------------|
| `src/sidepanel.tsx` | `side_panel.default_path` |
| `src/background/index.ts` | `background.service_worker` |
| `src/contents/bridge.ts` | `content_scripts` array entry |
| `src/background/messages/*.ts` | Internal routing only (no manifest) |
| `src/background/ports/*.ts` | Internal routing only (no manifest) |

### package.json

```json
{
  "name": "phalanx-agent-bridge",
  "displayName": "Phalanx Agent Bridge",
  "version": "0.1.0",
  "description": "Connect browser tabs to the Phalanx agent runtime",
  "author": "Havy Technologies",
  "scripts": {
    "dev": "plasmo dev",
    "build": "plasmo build",
    "build:zip": "plasmo build --zip",
    "test": "vitest"
  },
  "dependencies": {
    "@plasmohq/messaging": "^0.7",
    "@plasmohq/storage": "^1.12",
    "plasmo": "^0.89",
    "react": "^19.0",
    "react-dom": "^19.0"
  },
  "devDependencies": {
    "@types/chrome": "^0.0.280",
    "typescript": "^5.7",
    "vitest": "^3.0"
  },
  "manifest": {
    "permissions": [
      "sidePanel",
      "storage",
      "tabs",
      "activeTab",
      "scripting",
      "webRequest",
      "webNavigation",
      "nativeMessaging",
      "alarms"
    ],
    "host_permissions": [
      "<all_urls>"
    ],
    "side_panel": {
      "default_path": "sidepanel.html"
    }
  }
}
```

### tsconfig.json

```json
{
  "extends": "plasmo/templates/tsconfig.base",
  "compilerOptions": {
    "strict": true,
    "paths": { "~*": ["./src/*"] },
    "baseUrl": "."
  },
  "include": ["src/**/*", ".plasmo/**/*"]
}
```

---

## 2. Wire Protocol Types

TypeScript types mirroring the integration spec. These are the source of truth for the extension side. See `../integration/SPEC.md` Section 1 for full semantics.

```typescript
// src/lib/types.ts

// -- Discriminator --

interface BaseMessage {
  type: string
}

// -- Extension to Daemon --

interface TabConnect extends BaseMessage {
  type: "tab.connect"
  tabId: number
  sessionId: string
  url: string
  title: string
  domain: string
}

// sessionId is set to chrome.runtime.id in every tab.connect message.
// Stable across service worker restarts, unique per Chrome profile/extension install.

interface TabDisconnect extends BaseMessage {
  type: "tab.disconnect"
  tabId: number
}

interface TabNavigate extends BaseMessage {
  type: "tab.navigate"
  tabId: number
  url: string
  title: string
}

interface DomSnapshot extends BaseMessage {
  type: "dom.snapshot"
  tabId: number
  html: string
  selector: string
  timestamp: number
}

interface DomMutations extends BaseMessage {
  type: "dom.mutations"
  tabId: number
  mutations: MutationSummary[]
  timestamp: number
}

interface MutationSummary {
  type: "childList" | "attributes" | "characterData"
  target: string
  addedCount?: number
  removedCount?: number
  attr?: string
  value?: string
}

interface DomResponse extends BaseMessage {
  type: "dom.response"
  tabId: number
  requestId: string
  elements: Record<string, string>[]
}

interface NetRequest extends BaseMessage {
  type: "net.request"
  tabId: number
  requestId: string
  method: string
  url: string
  timestamp: number
}

interface NetResponse extends BaseMessage {
  type: "net.response"
  tabId: number
  requestId: string
  url: string
  status: number
  contentType: string
  bodyPreview?: string
  durationMs: number
  timestamp: number
}

interface UserAction extends BaseMessage {
  type: "user.action"
  tabId: number
  action: "click" | "type" | "select" | "scroll" | "submit"
  target: string
  value?: string
  timestamp: number
}

interface ActionResult extends BaseMessage {
  type: "action.result"
  tabId: number
  actionId: string
  success: boolean
  data?: Record<string, unknown>
  error?: string
}

interface FlowPressure extends BaseMessage {
  type: "flow.pressure"
  tabId: number
  bufferDepth: number
}

interface UserChat extends BaseMessage {
  type: "user.chat"
  tabId: number
  text: string
}

type ExtensionMessage =
  | TabConnect | TabDisconnect | TabNavigate
  | DomSnapshot | DomMutations | DomResponse
  | NetRequest | NetResponse
  | UserAction | UserChat | ActionResult | FlowPressure

// -- Daemon to Extension --

interface ActionExecute extends BaseMessage {
  type: "action.execute"
  tabId: number
  actionId: string
  steps: ActionStep[]
}

interface ActionCancel extends BaseMessage {
  type: "action.cancel"
  tabId: number
  actionId: string
}

interface DomRequestCmd extends BaseMessage {
  type: "dom.request"
  tabId: number
  requestId: string
  selector: string
  attrs?: string[]
  limit?: number
}

interface UiUpdate extends BaseMessage {
  type: "ui.update"
  target: "status" | "confidence" | "conversation"
  data: Record<string, unknown>
}

interface FlowThrottle extends BaseMessage {
  type: "flow.throttle"
  tabId: number
  maxEventsPerSec: number
}

interface FlowResume extends BaseMessage {
  type: "flow.resume"
  tabId: number
}

type DaemonMessage =
  | ActionExecute | ActionCancel | DomRequestCmd
  | UiUpdate | FlowThrottle | FlowResume

// -- Action Steps --

type ActionStep =
  | { op: "click"; selector: string }
  | { op: "clickAll"; selector: string; delayMs?: number }
  | { op: "type"; selector: string; value: string; mainWorld?: boolean }
  | { op: "fill"; selector: string; value: string; mainWorld?: boolean }
  | { op: "select"; selector: string; value: string }
  | { op: "check"; selector: string; checked: boolean }
  | { op: "press"; key: string }
  | { op: "scroll"; selector: string; x?: number; y?: number }
  | { op: "waitForSelector"; selector: string; timeoutMs?: number }
  | { op: "waitForRemoval"; selector: string; timeoutMs?: number }
  | { op: "waitForText"; selector: string; text: string; timeoutMs?: number }
  | { op: "waitForNetwork"; urlPattern: string; timeoutMs?: number }
  | { op: "getAttribute"; selector: string; attr: string }
  | { op: "getTextContent"; selector: string }
  | { op: "evaluate"; expression: string; mainWorld?: boolean }
  | { op: "delay"; ms: number }
```

---

## 3. Content Script Architecture

File: `src/contents/bridge.ts`

The content script is the extension's presence inside the web page. It runs in Chrome's CONTENT (isolated) world, which means it shares the DOM with the page but has a separate JavaScript context. It cannot access `window` objects set by the page, and the page cannot see it.

### Module Split

The content script is split into four focused modules. This keeps the observation path separate from the actuation path, enabling future alternative dispatch mechanisms (e.g., CDP-based actuation for stealth mode) by replacing only the action-executor module without touching observation.

| Module | Responsibility | Exports |
|--------|---------------|---------|
| `lib/dom-observer.ts` | MutationObserver, rAF batching, mutation summarizer, CSS selector generator | `startObserving()`, `sendInitialSnapshot()`, `summarizeMutations()`, `generateSelector()` |
| `lib/dom-responder.ts` | `dom.request` handler: querySelectorAll, attribute extraction | `handleDomRequest()` |
| `lib/action-executor.ts` | All 16 action step ops, cancellation, `actionInProgress` flag. Most ops execute locally in ISOLATED world. `evaluate` always routes through SW for MAIN world execution. `fill` and `type` with `mainWorld: true` route through SW. | `executeAction()`, `actionInProgress`, `cancelledActions` |
| `lib/user-observer.ts` | User action listeners (click, input, change, submit, scroll) with `actionInProgress` guard | `startUserActionListeners()` |

The entry script (`contents/bridge.ts`) imports these modules and wires them to the port lifecycle:

```typescript
// contents/bridge.ts -- entry point, wiring only
import { startObserving, sendInitialSnapshot } from "~lib/dom-observer"
import { handleDomRequest } from "~lib/dom-responder"
import { executeAction, cancelledActions } from "~lib/action-executor"
import { startUserActionListeners } from "~lib/user-observer"
```

### Plasmo Config

```typescript
import type { PlasmoCSConfig } from "plasmo"

export const config: PlasmoCSConfig = {
  matches: ["<all_urls>"],
  run_at: "document_idle",
  all_frames: false
}
```

The content script is injected into all URLs but does nothing until the service worker activates it via a port connection. `document_idle` ensures the page is fully parsed before the script runs. `all_frames: false` restricts to the top frame -- iframes are not observed.

For programmatic injection when the user connects a tab after page load, the service worker uses `chrome.scripting.executeScript()`:

```typescript
await chrome.scripting.executeScript({
  target: { tabId },
  files: ["contents/bridge.js"]
})
```

### Lifecycle

```
1. Content script loads (declarative or programmatic injection)
2. Script registers chrome.runtime.onConnect listener
3. Waits for service worker to open port named "tab-bridge"
4. On port connect: starts MutationObserver, user action listeners
5. Sends tab.connect via port
6. Sends initial dom.snapshot via port
7. Streams dom.mutations, user.action via port
8. Receives action.execute, action.cancel, dom.request, flow.throttle/resume via port
9. On port disconnect: tears down observers, clears buffer
```

The content script is passive until the service worker opens a port. This prevents unnecessary work on tabs the user has not connected.

### Port Communication

The content script uses `chrome.runtime.Port` for all communication with the service worker. The **service worker initiates** the port via `chrome.tabs.connect(tabId, { name: "tab-bridge" })` (see `connect-tab.ts` and `reconnectTab`). The content script listens via `chrome.runtime.onConnect`.

See **Tab ID Initialization** section below for the complete port handler. The service worker sends `{ type: "__init", tabId }` as the first message, and the content script starts observation after receiving the tab ID.

```typescript
let port: chrome.runtime.Port | null = null
let observer: MutationObserver | null = null

chrome.runtime.onConnect.addListener((incomingPort) => {
  if (incomingPort.name !== "tab-bridge") return
  // See Tab ID Initialization for the __init handshake that follows
  port = incomingPort
  port.onDisconnect.addListener(handleDisconnect)
  sendInitialSnapshot()
})
```

### MutationObserver Setup

The observer watches `document.body` for subtree changes. Raw `MutationRecord` objects are not sent over the wire -- they contain live DOM references and are not serializable. The content script summarizes them.

```typescript
function startObserving(): void {
  let pendingMutations: MutationRecord[] = []
  let rafScheduled = false

  observer = new MutationObserver((records) => {
    pendingMutations.push(...records)

    if (!rafScheduled) {
      rafScheduled = true
      requestAnimationFrame(() => {
        const summarized = summarizeMutations(pendingMutations)
        pendingMutations = []
        rafScheduled = false

        if (summarized.length > 0) {
          enqueueMessage({
            type: "dom.mutations",
            tabId: currentTabId,
            mutations: summarized,
            timestamp: Date.now()
          })
        }
      })
    }
  })

  observer.observe(document.body, {
    childList: true,
    attributes: true,
    characterData: true,
    subtree: true,
    // Note: attributeFilter does NOT support wildcards. Omit it to observe all
    // attribute changes. Filtering is done in the summarizer instead, which
    // drops mutations on non-semantic attributes (style, width, height, etc.).
    // attributeFilter: omitted -- observe all, filter in summarizer
  })
}
```

`requestAnimationFrame` batching collapses all mutations within one frame (~16ms at 60fps) into a single message. This is critical for React/Vue/Svelte pages that trigger dozens of mutations per state update.

`attributeFilter` is omitted because it does not support wildcard patterns (`"data-*"` would be treated as a literal attribute name). Instead, the `MutationObserver` observes all attribute changes, and the mutation summarizer filters non-semantic attributes (style, width, height, animation-related) before batching. This adds negligible overhead since the summarizer runs on the same rAF tick.

### Mutation Summarizer

Converts raw `MutationRecord[]` into the `MutationSummary[]` format defined in the integration spec. The summarizer applies three filters:

1. **Visibility filter**: Mutations targeting elements with `offsetParent === null` (hidden) or inside `<script>`, `<style>`, `<noscript>` tags are dropped. Exception: elements with `position: fixed/sticky` may have `offsetParent === null` while being visible -- check `getComputedStyle(el).display !== 'none'` for these.

2. **Deduplication**: Multiple attribute mutations on the same element within one frame are collapsed to the latest value. Multiple childList mutations on the same parent are summed.

3. **Selector generation**: Each mutation's `target` field is a CSS selector string, not an element reference. The selector generator uses a priority cascade:
   - `[data-testid="value"]` or `[data-id="value"]` if present
   - `#id` if the element has a unique ID (verified via `document.querySelectorAll('#id').length === 1`)
   - `[aria-label="value"]` if present
   - `tag.class1.class2` with enough specificity to be unique
   - Fallback: `nth-child` path from nearest identifiable ancestor

```typescript
function summarizeMutations(records: MutationRecord[]): MutationSummary[] {
  const childListMap = new Map<string, { added: number; removed: number }>()
  const attrMap = new Map<string, { attr: string; value: string }>()
  const charDataSet = new Set<string>()

  for (const record of records) {
    const target = record.target as Element
    if (!isVisible(target)) continue

    const selector = generateSelector(target)

    switch (record.type) {
      case "childList": {
        const entry = childListMap.get(selector) ?? { added: 0, removed: 0 }
        entry.added += countVisibleNodes(record.addedNodes)
        entry.removed += record.removedNodes.length
        childListMap.set(selector, entry)
        break
      }
      case "attributes": {
        const key = `${selector}::${record.attributeName}`
        attrMap.set(key, {
          attr: record.attributeName!,
          value: (target as Element).getAttribute(record.attributeName!) ?? ""
        })
        break
      }
      case "characterData": {
        charDataSet.add(selector)
        break
      }
    }
  }

  const summaries: MutationSummary[] = []

  for (const [target, counts] of childListMap) {
    summaries.push({
      type: "childList",
      target,
      addedCount: counts.added,
      removedCount: counts.removed
    })
  }

  for (const [key, data] of attrMap) {
    const target = key.split("::")[0]
    summaries.push({ type: "attributes", target, attr: data.attr, value: data.value })
  }

  for (const target of charDataSet) {
    summaries.push({ type: "characterData", target })
  }

  return summaries
}
```

### CSS Selector Generator

Generates a stable, minimal CSS selector for a given DOM element. Stability matters because the daemon stores selectors in lego definitions -- if selectors are fragile, legos break on minor UI changes.

```typescript
function generateSelector(el: Element): string {
  // Priority 1: test IDs
  const testId = el.getAttribute("data-testid") ?? el.getAttribute("data-id")
  if (testId) return `[data-testid="${testId}"]`

  // Priority 2: unique ID
  if (el.id && document.querySelectorAll(`#${CSS.escape(el.id)}`).length === 1) {
    return `#${CSS.escape(el.id)}`
  }

  // Priority 3: aria-label
  const ariaLabel = el.getAttribute("aria-label")
  if (ariaLabel) {
    const sel = `${el.tagName.toLowerCase()}[aria-label="${CSS.escape(ariaLabel)}"]`
    if (document.querySelectorAll(sel).length === 1) return sel
  }

  // Priority 4: tag + classes (non-generated)
  const stableClasses = Array.from(el.classList)
    .filter(c => !/^[a-z]{1,3}[A-Z0-9]|^_|^css-|^sc-|^emotion/.test(c))
  if (stableClasses.length > 0) {
    const sel = `${el.tagName.toLowerCase()}.${stableClasses.join(".")}`
    if (document.querySelectorAll(sel).length === 1) return sel
  }

  // Priority 5: nth-child path
  const path: string[] = []
  let current: Element | null = el
  while (current && current !== document.body) {
    const parent = current.parentElement
    if (!parent) break
    const index = Array.from(parent.children).indexOf(current) + 1
    path.unshift(`${current.tagName.toLowerCase()}:nth-child(${index})`)
    current = parent
    if (path.length >= 4) break
  }
  return path.join(" > ")
}
```

### Action Step Executor

Implements all 16 ops from `../integration/SPEC.md` Section 1.3. Each op is a standalone async function dispatched by a switch on `step.op`.

```typescript
let actionInProgress = false

async function executeAction(msg: ActionExecute): Promise<void> {
  let lastData: Record<string, unknown> | undefined
  actionInProgress = true

  try {
    for (const step of msg.steps) {
      if (cancelledActions.has(msg.actionId)) {
        sendResult(msg, false, undefined, "cancelled")
        return
      }
      const result = await executeStep(step)
      if (result !== undefined) lastData = result
    }
    sendResult(msg, true, lastData)
  } catch (err) {
    sendResult(msg, false, undefined, (err as Error).message)
  } finally {
    actionInProgress = false
    cancelledActions.delete(msg.actionId)
  }
}

async function executeStep(step: ActionStep): Promise<Record<string, unknown> | undefined> {
  switch (step.op) {
    case "click": {
      const el = requireElement(step.selector)
      el.scrollIntoView({ behavior: "smooth", block: "center" })
      el.click()
      return
    }

    case "clickAll": {
      const els = document.querySelectorAll(step.selector)
      for (const el of els) {
        ;(el as HTMLElement).click()
        if (step.delayMs) await delay(step.delayMs)
      }
      return
    }

    case "type": {
      if (step.mainWorld) {
        // Route through service worker for MAIN world execution.
        // Used when ISOLATED world typing doesn't trigger framework state updates.
        const expr = `(() => {
          const el = document.querySelector(${JSON.stringify(step.selector)});
          if (!el) throw new Error('Element not found: ${step.selector}');
          el.focus();
          const nativeSet = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
          nativeSet.call(el, ${JSON.stringify(step.value)});
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
          return true;
        })()`
        const result = await evaluateViaServiceWorker(expr)
        if (!result.ok) throw new Error(`type (mainWorld) failed: ${result.error}`)
        return
      }
      const el = requireElement(step.selector) as HTMLInputElement
      el.focus()
      el.value = ""
      el.dispatchEvent(new Event("input", { bubbles: true }))
      for (const char of step.value) {
        el.value += char
        el.dispatchEvent(new KeyboardEvent("keydown", { key: char, bubbles: true }))
        el.dispatchEvent(new KeyboardEvent("keypress", { key: char, bubbles: true }))
        el.dispatchEvent(new Event("input", { bubbles: true }))
        el.dispatchEvent(new KeyboardEvent("keyup", { key: char, bubbles: true }))
      }
      el.dispatchEvent(new Event("change", { bubbles: true }))
      return
    }

    case "fill": {
      if (step.mainWorld) {
        // Route through service worker for MAIN world execution.
        // Uses native value setter to bypass React/Angular/Vue controlled input wrappers.
        const expr = `(() => {
          const el = document.querySelector(${JSON.stringify(step.selector)});
          if (!el) throw new Error('Element not found: ${step.selector}');
          el.focus();
          const nativeSet = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
          nativeSet.call(el, ${JSON.stringify(step.value)});
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
          return true;
        })()`
        const result = await evaluateViaServiceWorker(expr)
        if (!result.ok) throw new Error(`fill (mainWorld) failed: ${result.error}`)
        return
      }
      const el = requireElement(step.selector) as HTMLInputElement
      el.focus()
      el.value = step.value
      el.dispatchEvent(new Event("input", { bubbles: true }))
      el.dispatchEvent(new Event("change", { bubbles: true }))
      return
    }

    case "select": {
      const el = requireElement(step.selector) as HTMLSelectElement
      el.value = step.value
      el.dispatchEvent(new Event("change", { bubbles: true }))
      return
    }

    case "check": {
      const el = requireElement(step.selector) as HTMLInputElement
      el.checked = step.checked
      el.dispatchEvent(new Event("change", { bubbles: true }))
      return
    }

    case "press": {
      const target = document.activeElement ?? document.body
      for (const eventType of ["keydown", "keypress", "keyup"] as const) {
        target.dispatchEvent(new KeyboardEvent(eventType, {
          key: step.key, code: step.key, bubbles: true
        }))
      }
      return
    }

    case "scroll": {
      const el = requireElement(step.selector)
      if (step.x !== undefined || step.y !== undefined) {
        el.scrollTo({ left: step.x ?? 0, top: step.y ?? 0, behavior: "smooth" })
      } else {
        el.scrollIntoView({ behavior: "smooth", block: "center" })
      }
      return
    }

    case "waitForSelector":
      await pollFor(() => document.querySelector(step.selector) !== null, step.timeoutMs ?? 5000,
        `Element not found: ${step.selector}`)
      return

    case "waitForRemoval":
      await pollFor(() => document.querySelector(step.selector) === null, step.timeoutMs ?? 5000,
        `Element still present: ${step.selector}`)
      return

    case "waitForText": {
      await pollFor(() => {
        const el = document.querySelector(step.selector)
        return el !== null && (el.textContent ?? "").includes(step.text)
      }, step.timeoutMs ?? 5000, `Text "${step.text}" not found in ${step.selector}`)
      return
    }

    case "waitForNetwork":
      // Requires cooperation with service worker. Send request via port,
      // service worker responds when matching network event completes.
      await waitForNetworkViaServiceWorker(step.urlPattern, step.timeoutMs ?? 10000)
      return

    case "getAttribute": {
      const el = requireElement(step.selector)
      return { [step.attr]: el.getAttribute(step.attr) }
    }

    case "getTextContent": {
      const el = requireElement(step.selector)
      return { textContent: el.textContent }
    }

    case "evaluate": {
      // Execute in MAIN world via chrome.scripting.executeScript routed through
      // the service worker. HAS access to page-context globals (window.*, framework state).
      // Return values must be structured-cloneable (no DOM nodes, functions, Promises, Symbols).
      // Expressions must be synchronous.
      const result = await evaluateViaServiceWorker(step.expression)
      if (!result.ok) throw new Error(`evaluate failed: ${result.error}`)
      return { result: result.value }
    }

    case "delay":
      await new Promise(r => setTimeout(r, step.ms))
      return
  }
}

function requireElement(selector: string): Element {
  const el = document.querySelector(selector)
  if (!el) throw new Error(`Element not found: ${selector}`)
  return el
}

function pollFor(predicate: () => boolean, timeoutMs: number, errorMsg: string): Promise<void> {
  return new Promise((resolve, reject) => {
    if (predicate()) { resolve(); return }

    const start = Date.now()
    function check() {
      if (predicate()) { resolve(); return }
      if (Date.now() - start >= timeoutMs) {
        reject(new Error(`${errorMsg} (${timeoutMs}ms)`))
        return
      }
      requestAnimationFrame(check)
    }
    requestAnimationFrame(check)
  })
}
```

### User Action Observer

Detects user interactions on the page and sends `user.action` messages. These feed the policy learning engine on the daemon side. The `actionInProgress` guard (from `action-executor.ts`) prevents agent-executed actions from being recorded as user actions, which would corrupt the policy learning data. This is also essential for future stealth mode where CDP-dispatched events have `isTrusted:true` and are indistinguishable from real user input.

```typescript
function startUserActionListeners(): void {
  document.addEventListener("click", (e) => {
    if (actionInProgress) return
    const target = e.target as Element
    if (!target || target === document.body) return
    enqueueMessage({
      type: "user.action",
      tabId: currentTabId,
      action: "click",
      target: generateSelector(target),
      timestamp: Date.now()
    })
  }, { capture: true, passive: true })

  document.addEventListener("input", (e) => {
    if (actionInProgress) return
    const target = e.target as HTMLInputElement
    if (!target) return
    enqueueMessage({
      type: "user.action",
      tabId: currentTabId,
      action: "type",
      target: generateSelector(target),
      value: target.value,
      timestamp: Date.now()
    })
  }, { capture: true, passive: true })

  document.addEventListener("change", (e) => {
    if (actionInProgress) return
    const target = e.target as HTMLSelectElement
    if (!target || target.tagName !== "SELECT") return
    enqueueMessage({
      type: "user.action",
      tabId: currentTabId,
      action: "select",
      target: generateSelector(target),
      value: target.value,
      timestamp: Date.now()
    })
  }, { capture: true, passive: true })

  document.addEventListener("submit", (e) => {
    if (actionInProgress) return
    const target = e.target as HTMLFormElement
    if (!target) return
    enqueueMessage({
      type: "user.action",
      tabId: currentTabId,
      action: "submit",
      target: generateSelector(target),
      timestamp: Date.now()
    })
  }, { capture: true, passive: true })

  // Scroll: debounced, only on significant movement
  let lastScrollY = window.scrollY
  let scrollTimeout: ReturnType<typeof setTimeout> | null = null
  document.addEventListener("scroll", () => {
    if (actionInProgress) return
    if (scrollTimeout) return
    scrollTimeout = setTimeout(() => {
      scrollTimeout = null
      const delta = Math.abs(window.scrollY - lastScrollY)
      if (delta > 200) {
        lastScrollY = window.scrollY
        enqueueMessage({
          type: "user.action",
          tabId: currentTabId,
          action: "scroll",
          target: "window",
          value: String(window.scrollY),
          timestamp: Date.now()
        })
      }
    }, 300)
  }, { capture: true, passive: true })
}
```

All listeners use `capture: true` to intercept events before page handlers can `stopPropagation()`. `passive: true` ensures no scroll jank.

### DOM Query Responder

Handles `dom.request` messages from the daemon.

```typescript
function handleDomRequest(msg: DomRequestCmd): void {
  const elements = document.querySelectorAll(msg.selector)
  const results: Record<string, string>[] = []
  const limit = msg.limit ?? elements.length

  for (let i = 0; i < Math.min(elements.length, limit); i++) {
    const el = elements[i]
    const entry: Record<string, string> = {}

    if (msg.attrs) {
      for (const attr of msg.attrs) {
        entry[attr] = el.getAttribute(attr) ?? ""
      }
    } else {
      // Default: all data-* attributes
      for (const attr of el.getAttributeNames()) {
        if (attr.startsWith("data-")) {
          entry[attr] = el.getAttribute(attr) ?? ""
        }
      }
    }
    results.push(entry)
  }

  sendToPort({
    type: "dom.response",
    tabId: currentTabId,
    requestId: msg.requestId,
    elements: results
  })
}
```

### Buffer Management

The content script maintains a bounded outbound buffer for messages that cannot be delivered to the service worker (port disconnected, service worker dead).

```typescript
const BUFFER_CAPACITY = 64
const PRESSURE_THRESHOLD = 16
let buffer: ExtensionMessage[] = []
let pressureReported = false

function enqueueMessage(msg: ExtensionMessage): void {
  if (port) {
    port.postMessage(msg)
  } else {
    buffer.push(msg)
    if (buffer.length > BUFFER_CAPACITY) {
      buffer.shift() // Drop oldest
    }
  }

  // Report pressure when buffer exceeds threshold
  if (buffer.length >= PRESSURE_THRESHOLD && !pressureReported) {
    pressureReported = true
    const pressureMsg: FlowPressure = {
      type: "flow.pressure",
      tabId: currentTabId,
      bufferDepth: buffer.length
    }
    if (port) port.postMessage(pressureMsg)
  }

  if (buffer.length < PRESSURE_THRESHOLD) {
    pressureReported = false
  }
}

function flushBuffer(): void {
  if (!port || buffer.length === 0) return
  for (const msg of buffer) {
    port.postMessage(msg)
  }
  buffer = []
  pressureReported = false
}
```

### Throttle Response

When the daemon sends `flow.throttle`, the content script adjusts its observation frequency.

```typescript
let throttleFrameSkip = 1 // 1 = every frame, 4 = every 4th frame
let frameCount = 0

// In the MutationObserver callback:
if (!rafScheduled) {
  rafScheduled = true
  requestAnimationFrame(() => {
    frameCount++
    if (frameCount % throttleFrameSkip !== 0) {
      rafScheduled = false
      return // Skip this frame
    }
    // ... process mutations
  })
}

function handleFlowThrottle(msg: FlowThrottle): void {
  if (msg.maxEventsPerSec === 0) {
    // Pause all non-essential observations
    observer?.disconnect()
    return
  }
  // Map events/sec to frame skip: 60fps / maxEventsPerSec
  throttleFrameSkip = Math.max(1, Math.round(60 / msg.maxEventsPerSec))
}

function handleFlowResume(): void {
  throttleFrameSkip = 1
  if (!observer) {
    startObserving()
    sendInitialSnapshot() // Fill the observation gap from the pause period
  }
}
```

### Daemon Message Dispatch

The `handleDaemonMessage` function dispatches incoming messages from the service worker to the appropriate handler:

```typescript
function handleDaemonMessage(msg: DaemonMessage | { type: string }): void {
  switch (msg.type) {
    case "action.execute":
      executeAction(msg as ActionExecute)
      break
    case "action.cancel":
      cancelledActions.add((msg as ActionCancel).actionId)
      break
    case "dom.request":
      handleDomRequest(msg as DomRequestCmd)
      break
    case "flow.throttle":
      handleFlowThrottle(msg as FlowThrottle)
      break
    case "flow.resume":
      handleFlowResume()
      break
    case "__resend_snapshot":
      // Internal message from service worker on reconnection
      sendInitialSnapshot()
      break
  }
}

const cancelledActions = new Set<string>()
```

### Tab ID Initialization

Content scripts cannot self-discover their tab ID. The service worker passes it when opening the port:

```typescript
let currentTabId = -1

chrome.runtime.onConnect.addListener((incomingPort) => {
  if (incomingPort.name !== "tab-bridge") return

  // Service worker sends tabId as the first message on the port
  port = incomingPort
  port.onMessage.addListener((msg) => {
    if (msg.type === "__init" && typeof msg.tabId === "number") {
      currentTabId = msg.tabId
      startObserving()
      sendTabConnect()
      sendInitialSnapshot()
      // Switch to daemon message handler for subsequent messages
      port!.onMessage.removeListener(arguments.callee as any)
      port!.onMessage.addListener(handleDaemonMessage)
      return
    }
    handleDaemonMessage(msg)
  })
  port.onDisconnect.addListener(handleDisconnect)
})
```

The service worker sends `{ type: "__init", tabId: 42 }` as the first message after opening the port. This replaces the original `onConnect` listener shown in the Port Communication section.

### waitForNetwork Cross-Context Protocol

The `waitForNetwork` op requires coordination with the service worker because `chrome.webRequest` is only available there:

```typescript
function waitForNetworkViaServiceWorker(urlPattern: string, timeoutMs: number): Promise<void> {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      reject(new Error(`Network request matching "${urlPattern}" not completed (${timeoutMs}ms)`))
    }, timeoutMs)

    const handler = (msg: { type: string; urlPattern?: string }) => {
      if (msg.type === "__network_matched" && msg.urlPattern === urlPattern) {
        clearTimeout(timeout)
        port?.onMessage.removeListener(handler)
        resolve()
      }
    }

    port?.onMessage.addListener(handler)
    sendToPort({ type: "__waitForNetwork", urlPattern } as any)
  })
}
```

The service worker receives `__waitForNetwork`, adds a one-shot listener to `chrome.webRequest.onCompleted` matching the URL pattern, and sends `__network_matched` back when it fires. These are internal extension messages, not daemon wire protocol.

### evaluate Cross-Context Protocol

The `evaluate` op (and `fill`/`type` with `mainWorld: true`) requires execution in the page's MAIN world, which is only possible from the service worker via `chrome.scripting.executeScript`. The content script sends an internal message to the SW and awaits the result.

```typescript
function evaluateViaServiceWorker(expression: string): Promise<{ ok: boolean; value?: unknown; error?: string; stack?: string }> {
  return new Promise((resolve, reject) => {
    // Use parent action's remaining timeout, or 10s default
    const timeoutMs = currentActionRemainingMs ?? 10_000
    const timeout = setTimeout(() => {
      port?.onMessage.removeListener(handler)
      reject(new Error(`evaluate via service worker timed out (${timeoutMs}ms)`))
    }, timeoutMs)

    const handler = (msg: { type: string; result?: { ok: boolean; value?: unknown; error?: string; stack?: string } }) => {
      if (msg.type === "__evaluateResult") {
        clearTimeout(timeout)
        port?.onMessage.removeListener(handler)
        resolve(msg.result!)
      }
    }

    port?.onMessage.addListener(handler)
    sendToPort({ type: "__evaluate", expression } as any)
  })
}
```

The service worker receives `__evaluate` and executes via `chrome.scripting.executeScript`:

```typescript
// In handlePortMessage (service worker), when msg.type === "__evaluate":
async function handleEvaluate(tabId: number, expression: string, port: chrome.runtime.Port): Promise<void> {
  try {
    const results = await chrome.scripting.executeScript({
      target: { tabId },
      world: "MAIN",
      func: (expr: string) => {
        try {
          return { ok: true, value: new Function("return " + expr)() }
        } catch (e: any) {
          return { ok: false, error: e.message, stack: e.stack }
        }
      },
      args: [expression],
    })

    const result = results[0]?.result ?? { ok: false, error: "No result from executeScript" }
    port.postMessage({ type: "__evaluateResult", result })
  } catch (e: any) {
    // chrome.scripting.executeScript itself failed (e.g., DataCloneError on return value)
    port.postMessage({ type: "__evaluateResult", result: { ok: false, error: e.message } })
  }
}
```

**Serialization constraints:** `chrome.scripting.executeScript` uses structured clone serialization for return values. The following types throw `DataCloneError` and must not be returned: DOM nodes, functions, Promises, Symbols, `WeakMap`, `WeakSet`. Expressions must be synchronous -- returning a Promise produces a `DataCloneError`.

These are internal extension messages (`__evaluate` / `__evaluateResult`), not daemon wire protocol. Same pattern as `__waitForNetwork` / `__network_matched`.

### Timeout on Service Worker Round-Trips

Both `__waitForNetwork` and `__evaluate` involve a round-trip to the service worker. If the SW is terminated or unresponsive, the content script must not hang indefinitely.

**Timeout strategy:** Use the parent action's remaining timeout when available. The `executeAction` loop tracks elapsed time against the action's total timeout budget. Each SW round-trip step inherits whatever time remains. If no parent timeout context exists (standalone evaluate), default to 10 seconds.

If the service worker does not respond within the timeout, the step fails and `action.result` reports the timeout error. The SW may still complete the operation after timeout -- the content script ignores late `__evaluateResult` / `__network_matched` messages because the one-shot handler was removed on timeout.

### isConnectedTab Helper

```typescript
function isConnectedTab(tabId: number): boolean {
  // Check against the in-memory state loaded from chrome.storage.session
  return loadedState?.connectedTabs.includes(tabId) ?? false
}

let loadedState: BridgeState | null = null
// Refreshed on every state mutation via loadState()
```

### Teardown

```typescript
function handleDisconnect(): void {
  port = null
  observer?.disconnect()
  observer = null
  // Buffer persists -- flushed if service worker reconnects
}
```

### v1 Known Limitations

| Limitation | Behavior | Workaround |
|---|---|---|
| Closed shadow DOM roots | `querySelector` cannot pierce closed shadow roots. Elements inside are invisible to all selector-based ops. | `evaluate` op with MAIN world access can reach open shadow roots via `element.shadowRoot`. Closed roots require host cooperation or CDP. |
| Cross-origin iframes | Content script runs in top frame only (`all_frames: false`). DOM inside cross-origin iframes is inaccessible. | `chrome.scripting.executeScript` with `frameIds` can target specific frames. Requires frame discovery. |
| Navigation race during action | A `click` that triggers full navigation destroys the content script mid-action. The service worker detects this via port disconnect. No `action.result` is sent. | Daemon's action timeout handles the missing result. Service worker could synthesize a failure result on port disconnect, but v1 relies on daemon timeout. |
| `isTrusted:false` events | All DOM events dispatched by content script ops (`click`, `type`, `fill`, `press`) have `isTrusted: false`. Sites checking `event.isTrusted` reject them. | CDP `Input.dispatchMouseEvent` / `Input.dispatchKeyEvent` is the only path to `isTrusted: true`. Reserved for v2. |

---

## 4. Service Worker Architecture

File: `src/background/index.ts`

The service worker is the extension's central hub. It manages the WebSocket to the daemon, routes messages between content scripts and daemon, routes messages between daemon and side panel, and handles tab lifecycle events.

### MV3 Constraints (Non-Negotiable)

- **30-second idle termination.** Chrome kills the service worker after 30 seconds with no pending events. All state in module-level variables is lost. State MUST be persisted to `chrome.storage.session` immediately after every mutation.
- **5-minute maximum lifetime.** Even with active work, Chrome may terminate after 5 minutes.
- **Event listeners MUST be registered synchronously at top level.** If a listener is registered inside an async callback or after an `await`, Chrome may restart the service worker between registration and event, missing the event.
- **No DOM access.** `document` and `window` (as a DOM API) are not available.
- **No `setTimeout`/`setInterval` for scheduling.** Timers are cancelled on termination. Use `chrome.alarms` for anything beyond immediate use.

### Top-Level Event Registration

Every event listener is registered synchronously at the top of `background/index.ts`. No async initialization precedes them.

```typescript
// src/background/index.ts
// -- ALL LISTENERS REGISTERED SYNCHRONOUSLY AT TOP LEVEL --

import { initWebSocket } from "~lib/ws-manager"
import { bootstrapNativeMessaging } from "~lib/native-bootstrap"
import { routeDaemonMessage } from "~lib/router"
import { loadState, persistState, type BridgeState } from "~lib/state"
import { setupNetworkObserver } from "~lib/network-observer"

// Tab lifecycle
chrome.tabs.onRemoved.addListener(handleTabRemoved)
chrome.tabs.onUpdated.addListener(handleTabUpdated)

// Navigation detection
chrome.webNavigation.onCommitted.addListener(handleNavigation)

// Side panel behavior
chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true })

// Network observation
setupNetworkObserver()

// Content script port connections
chrome.runtime.onConnect.addListener(handlePortConnect)

// Extension install/update
chrome.runtime.onInstalled.addListener(handleInstalled)
chrome.runtime.onStartup.addListener(handleStartup)

// Keepalive alarm
chrome.alarms.onAlarm.addListener(handleAlarm)

// -- ASYNC INITIALIZATION (after all listeners registered) --
initialize()
```

### Initialization Sequence

```typescript
async function initialize(): Promise<void> {
  const state = await loadState()

  // Step 1: Discover daemon port via Native Messaging
  const wsUrl = await bootstrapNativeMessaging()
  if (!wsUrl) {
    await persistState({ ...state, daemonStatus: "offline" })
    return
  }

  // Step 2: Open WebSocket to daemon
  initWebSocket(wsUrl, {
    onMessage: (msg: DaemonMessage) => routeDaemonMessage(msg, state),
    onClose: () => handleWsClose(state),
    onOpen: () => handleWsOpen(state)
  })

  // Step 3: Reconnect previously active tabs
  for (const tabId of state.connectedTabs) {
    try {
      const tab = await chrome.tabs.get(tabId)
      if (tab) reconnectTab(tabId, tab)
    } catch {
      // Tab no longer exists
      await removeConnectedTab(tabId, state)
    }
  }
}
```

### Native Messaging Bootstrap

File: `src/lib/native-bootstrap.ts`

The service worker calls `chrome.runtime.connectNative("com.phalanx.bridge")` to launch the Native Messaging host. The host reads `~/.phalanx/daemon.lock` and responds with the WebSocket URL. The native port also serves as a keepalive -- Chrome will not terminate the service worker while a `connectNative()` port is open.

```typescript
let nativePort: chrome.runtime.Port | null = null

export async function bootstrapNativeMessaging(): Promise<string | null> {
  return new Promise((resolve) => {
    try {
      nativePort = chrome.runtime.connectNative("com.phalanx.bridge")
    } catch {
      resolve(null)
      return
    }

    const timeout = setTimeout(() => {
      resolve(null)
    }, 5000)

    nativePort.onMessage.addListener((msg: { wsUrl?: string; error?: string }) => {
      clearTimeout(timeout)
      if (msg.wsUrl) {
        resolve(msg.wsUrl)
      } else {
        resolve(null)
      }
    })

    nativePort.onDisconnect.addListener(() => {
      nativePort = null
      // Native host exited. Not fatal -- the WS connection survives independently.
      // Attempt to reconnect native port periodically for keepalive.
      chrome.alarms.create("native-reconnect", { delayInMinutes: 0.5 })
    })

    // Send empty message to trigger the host's read loop
    nativePort.postMessage({})
  })
}
```

The native port's sole purpose is port discovery and keepalive. No application data flows through it. The 1MB outbound cap on Native Messaging is irrelevant because the host only ever sends one small JSON response.

### WebSocket Management

File: `src/lib/ws-manager.ts`

```typescript
let ws: WebSocket | null = null
let reconnectAttempt = 0
const MAX_BACKOFF_MS = 30_000
const BUFFERED_AMOUNT_PAUSE = 1_048_576   // 1MB
const BUFFERED_AMOUNT_RESUME = 524_288    // 512KB

interface WsCallbacks {
  onMessage: (msg: DaemonMessage) => void
  onClose: () => void
  onOpen: () => void
}

let callbacks: WsCallbacks | null = null
let paused = false
let sendQueue: string[] = []

export function initWebSocket(url: string, cbs: WsCallbacks): void {
  callbacks = cbs
  connect(url)
}

function connect(url: string): void {
  ws = new WebSocket(url)

  ws.onopen = () => {
    reconnectAttempt = 0
    paused = false
    flushSendQueue()
    callbacks?.onOpen()
  }

  ws.onmessage = (event) => {
    try {
      const msg = JSON.parse(event.data as string) as DaemonMessage
      callbacks?.onMessage(msg)
    } catch {
      // Malformed message -- drop silently per protocol evolution rule
    }
  }

  ws.onclose = (event) => {
    ws = null
    callbacks?.onClose()
    scheduleReconnect(url)
  }

  ws.onerror = () => {
    // onerror always followed by onclose in browsers
  }
}

export function sendToDaemon(msg: ExtensionMessage): void {
  const json = JSON.stringify(msg)

  if (!ws || ws.readyState !== WebSocket.OPEN) {
    sendQueue.push(json)
    return
  }

  if (paused) {
    sendQueue.push(json)
    return
  }

  ws.send(json)

  // Monitor bufferedAmount for backpressure
  if (ws.bufferedAmount > BUFFERED_AMOUNT_PAUSE) {
    paused = true
    checkBufferedAmount()
  }
}

function checkBufferedAmount(): void {
  if (!ws || !paused) return
  if (ws.bufferedAmount < BUFFERED_AMOUNT_RESUME) {
    paused = false
    flushSendQueue()
  } else {
    // Check again in 50ms
    setTimeout(checkBufferedAmount, 50)
  }
}

function flushSendQueue(): void {
  while (sendQueue.length > 0 && ws?.readyState === WebSocket.OPEN && !paused) {
    ws.send(sendQueue.shift()!)
    if (ws.bufferedAmount > BUFFERED_AMOUNT_PAUSE) {
      paused = true
      checkBufferedAmount()
      break
    }
  }
}

function scheduleReconnect(url: string): void {
  const baseMs = Math.min(1000 * Math.pow(2, reconnectAttempt), MAX_BACKOFF_MS)
  const jitterMs = Math.random() * 1000
  const delayMs = baseMs + jitterMs
  reconnectAttempt++

  // Use setTimeout for short reconnect delays (destroyed if SW terminates,
  // but chrome.alarms has a 30-second minimum which is too slow for initial retries)
  setTimeout(() => connect(url), delayMs)
}

export function isConnected(): boolean {
  return ws !== null && ws.readyState === WebSocket.OPEN
}
```

### Message Routing

File: `src/lib/router.ts`

The service worker is the message router. Three routing directions:

1. **Content script to daemon**: Content script sends via port, service worker forwards via WebSocket.
2. **Daemon to content script**: Daemon sends `action.execute`/`action.cancel`/`dom.request`/`flow.throttle`/`flow.resume` targeting a `tabId`, service worker forwards to that tab's port.
3. **Daemon to side panel**: Daemon sends `ui.update`, service worker forwards to the side panel's port.

```typescript
// Content script ports, keyed by tabId
const tabPorts = new Map<number, chrome.runtime.Port>()

// Side panel port
let sidePanelPort: chrome.runtime.Port | null = null

export function routeDaemonMessage(msg: DaemonMessage, state: BridgeState): void {
  switch (msg.type) {
    case "action.execute":
    case "action.cancel":
    case "dom.request":
    case "flow.throttle":
    case "flow.resume": {
      const port = tabPorts.get((msg as { tabId: number }).tabId)
      port?.postMessage(msg)
      break
    }

    case "ui.update": {
      sidePanelPort?.postMessage(msg)
      break
    }
  }
}

function handlePortConnect(port: chrome.runtime.Port): void {
  // Tab bridge ports are created and tracked in connect-tab.ts and reconnectTab(),
  // where the service worker calls chrome.tabs.connect(). This handler only
  // manages the side panel connection.

  if (port.name === "side-panel") {
    sidePanelPort = port
    port.onDisconnect.addListener(() => {
      sidePanelPort = null
    })
  }
}
```

### Network Event Observation

File: `src/lib/network-observer.ts`

The service worker uses `chrome.webRequest` in read-only mode (MV3 restriction) to observe network activity per tab. Blocking/modification is not available in MV3; use `chrome.declarativeNetRequest` for that (not needed here -- we only observe).

```typescript
export function setupNetworkObserver(): void {
  // Track pending requests for duration calculation
  const pendingRequests = new Map<string, { tabId: number; method: string; url: string; startTime: number }>()

  chrome.webRequest.onBeforeRequest.addListener(
    (details) => {
      if (details.tabId < 0) return // Not from a tab
      if (!isConnectedTab(details.tabId)) return

      pendingRequests.set(details.requestId, {
        tabId: details.tabId,
        method: details.method,
        url: details.url,
        startTime: details.timeStamp
      })

      sendToDaemon({
        type: "net.request",
        tabId: details.tabId,
        requestId: details.requestId,
        method: details.method,
        url: details.url,
        timestamp: Math.floor(details.timeStamp)
      })
    },
    { urls: ["<all_urls>"] }
  )

  chrome.webRequest.onCompleted.addListener(
    (details) => {
      const pending = pendingRequests.get(details.requestId)
      if (!pending) return
      pendingRequests.delete(details.requestId)

      const contentType = (details.responseHeaders ?? [])
        .find(h => h.name.toLowerCase() === "content-type")?.value ?? ""

      sendToDaemon({
        type: "net.response",
        tabId: pending.tabId,
        requestId: details.requestId,
        url: pending.url,
        status: details.statusCode,
        contentType,
        durationMs: Math.floor(details.timeStamp - pending.startTime),
        timestamp: Math.floor(details.timeStamp)
      })
    },
    { urls: ["<all_urls>"] },
    ["responseHeaders"]
  )

  chrome.webRequest.onErrorOccurred.addListener(
    (details) => {
      pendingRequests.delete(details.requestId)
    },
    { urls: ["<all_urls>"] }
  )
}
```

Note: `bodyPreview` is not available from `chrome.webRequest`. MV3 does not expose response bodies in the `webRequest` API. To capture response bodies, the content script would need to intercept `fetch`/`XMLHttpRequest` in the page's MAIN world. This is a v2 enhancement -- v1 sends `net.response` without `bodyPreview`.

### State Persistence

File: `src/lib/state.ts`

All service worker state that must survive restarts is persisted to `chrome.storage.session`. Writes happen immediately after every state mutation.

```typescript
export interface BridgeState {
  connectedTabs: number[]
  tabMeta: Record<number, { url: string; title: string; domain: string }>
  daemonStatus: "online" | "offline" | "reconnecting"
  wsUrl: string | null
}

const STORAGE_KEY = "bridge_state"

const defaultState: BridgeState = {
  connectedTabs: [],
  tabMeta: {},
  daemonStatus: "offline",
  wsUrl: null
}

export async function loadState(): Promise<BridgeState> {
  const result = await chrome.storage.session.get(STORAGE_KEY)
  return (result[STORAGE_KEY] as BridgeState) ?? { ...defaultState }
}

export async function persistState(state: BridgeState): Promise<void> {
  await chrome.storage.session.set({ [STORAGE_KEY]: state })
}

export async function addConnectedTab(tabId: number, meta: { url: string; title: string; domain: string }): Promise<void> {
  const state = await loadState()
  if (!state.connectedTabs.includes(tabId)) {
    state.connectedTabs.push(tabId)
  }
  state.tabMeta[tabId] = meta
  await persistState(state)

  // Backup to local storage for extension update survival
  await chrome.storage.local.set({
    connectedTabsBackup: state.connectedTabs,
    tabMetaBackup: state.tabMeta
  })
}

export async function removeConnectedTab(tabId: number): Promise<void> {
  const state = await loadState()
  state.connectedTabs = state.connectedTabs.filter(id => id !== tabId)
  delete state.tabMeta[tabId]
  await persistState(state)
  await chrome.storage.local.set({
    connectedTabsBackup: state.connectedTabs,
    tabMetaBackup: state.tabMeta
  })
}
```

Why two storage areas:
- `chrome.storage.session`: Service worker state. Cleared on browser restart. 10MB limit. Fast, in-memory access from service worker. Used for runtime state.
- `chrome.storage.local`: Persists across browser restarts and extension updates. 10MB limit. Used as backup for connected tabs list so the extension can recover from updates.

`chrome.storage.session` is cleared on any extension restart (update, dev reload, browser restart). Empirical verification of exact clearing conditions across Chrome versions is recommended during Phase 2. The code handles all cases safely via the `chrome.storage.local` backup (see `handleInstalled` and `handleStartup`).

### reconnectTab

Re-injects content script and re-establishes port connection for a tab after navigation or reconnection:

```typescript
async function reconnectTab(tabId: number, tab: chrome.tabs.Tab): Promise<void> {
  try {
    await chrome.scripting.executeScript({
      target: { tabId },
      files: ["contents/bridge.js"]
    })

    const port = chrome.tabs.connect(tabId, { name: "tab-bridge" })
    tabPorts.set(tabId, port)
    port.postMessage({ type: "__init", tabId })

    port.onMessage.addListener((msg: ExtensionMessage) => {
      sendToDaemon(msg)
    })

    port.onDisconnect.addListener(() => {
      tabPorts.delete(tabId)
      chrome.tabs.get(tabId)
        .then((t) => {
          sendToDaemon({ type: "tab.disconnect", tabId })
          if (t.url && isConnectedTab(tabId)) reconnectTab(tabId, t)
        })
        .catch(() => {
          sendToDaemon({ type: "tab.disconnect", tabId })
          removeConnectedTab(tabId)
        })
    })

    // Update stored metadata with new URL/domain
    if (tab.url) {
      const domain = new URL(tab.url).hostname
      await addConnectedTab(tabId, { url: tab.url, title: tab.title ?? "", domain })
    }
  } catch {
    // Injection failed (e.g., chrome:// page, PDF). Remove from connected tabs.
    await removeConnectedTab(tabId)
  }
}
```

### Tab Lifecycle Handlers

```typescript
function handleTabRemoved(tabId: number): void {
  if (tabPorts.has(tabId)) {
    sendToDaemon({ type: "tab.disconnect", tabId })
    tabPorts.get(tabId)?.disconnect()
    tabPorts.delete(tabId)
    removeConnectedTab(tabId)
  }
}

async function handleTabUpdated(tabId: number, changeInfo: chrome.tabs.TabChangeInfo, tab: chrome.tabs.Tab): Promise<void> {
  if (!isConnectedTab(tabId)) return

  // URL change detected via tabs.onUpdated (covers SPA navigations)
  if (changeInfo.url && tab.url) {
    sendToDaemon({
      type: "tab.navigate",
      tabId,
      url: tab.url,
      title: tab.title ?? ""
    })
    const domain = new URL(tab.url).hostname
    await addConnectedTab(tabId, { url: tab.url, title: tab.title ?? "", domain })
  }
}

function handleNavigation(details: chrome.webNavigation.WebNavigationCommittedDetails): void {
  if (details.frameId !== 0) return // Top frame only
  if (!isConnectedTab(details.tabId)) return

  // Full navigation detected. Two scenarios:
  // 1. Same-origin: content script may survive (SPA), port stays alive.
  //    Handled by tabs.onUpdated sending tab.navigate.
  // 2. Cross-origin: content script dies, port disconnects.
  //    The port onDisconnect handler (Section 4) detects the tab still exists,
  //    sends tab.disconnect, and calls reconnectTab() to re-inject.
  // No action needed here -- port lifecycle handles both cases.
}

async function handleInstalled(details: chrome.runtime.InstalledDetails): Promise<void> {
  // Session storage is cleared on ALL extension restarts (update, dev reload, install).
  // Always restore from local storage backup regardless of reason.
  const backup = await chrome.storage.local.get(["connectedTabsBackup", "tabMetaBackup"])
  if (backup.connectedTabsBackup) {
    await persistState({
      connectedTabs: backup.connectedTabsBackup,
      tabMeta: backup.tabMetaBackup ?? {},
      daemonStatus: "offline",
      wsUrl: null
    })
  }
}

async function handleStartup(): Promise<void> {
  // Browser launched. Session storage empty. Restore from local if available.
  const backup = await chrome.storage.local.get(["connectedTabsBackup", "tabMetaBackup"])
  if (backup.connectedTabsBackup) {
    await persistState({
      connectedTabs: backup.connectedTabsBackup,
      tabMeta: backup.tabMetaBackup ?? {},
      daemonStatus: "offline",
      wsUrl: null
    })
  }
  await initialize()
}

function handleAlarm(alarm: chrome.alarms.Alarm): void {
  if (alarm.name === "native-reconnect") {
    bootstrapNativeMessaging()
  }
}
```

### Reconnection Logic

When the WebSocket closes:

```typescript
async function handleWsClose(state: BridgeState): Promise<void> {
  state.daemonStatus = "reconnecting"
  await persistState(state)

  // Notify side panel
  sidePanelPort?.postMessage({
    type: "ui.update",
    target: "status",
    data: { state: "reconnecting" }
  } satisfies UiUpdate)
}

async function handleWsOpen(state: BridgeState): Promise<void> {
  state.daemonStatus = "online"
  await persistState(state)

  // Reconnect all active tabs -- See integration spec Section 3.3
  for (const tabId of state.connectedTabs) {
    try {
      const tab = await chrome.tabs.get(tabId)
      if (!tab.url) continue
      const domain = new URL(tab.url).hostname

      // Resend tab.connect with fresh metadata from chrome.tabs.get()
      sendToDaemon({
        type: "tab.connect",
        tabId,
        sessionId: chrome.runtime.id,
        url: tab.url,
        title: tab.title ?? "",
        domain
      })

      // Update stored metadata with fresh values
      await addConnectedTab(tabId, { url: tab.url, title: tab.title ?? "", domain })

      // Tell content script to send fresh snapshot (tabId already initialized)
      const port = tabPorts.get(tabId)
      if (port) {
        port.postMessage({ type: "__resend_snapshot" })
      } else {
        // Port died during disconnect -- re-inject content script
        reconnectTab(tabId, tab)
      }

    } catch {
      // Tab no longer exists
      await removeConnectedTab(tabId)
    }
  }

  // Notify side panel
  sidePanelPort?.postMessage({
    type: "ui.update",
    target: "status",
    data: { state: "connected" }
  } satisfies UiUpdate)
}
```

After reconnection, the service worker drives the state reconciliation protocol defined in `../integration/SPEC.md` Section 3. The daemon is passive -- it accepts the incoming `tab.connect` and `dom.snapshot` messages as if this were a first connection.

---

## 5. Plasmo Message Handlers

### background/messages/connect-tab.ts

Side panel requests the service worker to connect a specific tab.

```typescript
import type { PlasmoMessaging } from "@plasmohq/messaging"

interface ConnectRequest { tabId: number }
interface ConnectResponse { success: boolean; error?: string }

const handler: PlasmoMessaging.MessageHandler<ConnectRequest, ConnectResponse> = async (req, res) => {
  const { tabId } = req.body!

  try {
    const tab = await chrome.tabs.get(tabId)
    if (!tab.url) {
      res.send({ success: false, error: "Tab has no URL" })
      return
    }

    // Inject content script if not already present
    await chrome.scripting.executeScript({
      target: { tabId },
      files: ["contents/bridge.js"]
    })

    // Connect port to content script and register in tabPorts
    const port = chrome.tabs.connect(tabId, { name: "tab-bridge" })
    tabPorts.set(tabId, port)

    // Send tabId to content script (it cannot self-discover its tab ID)
    port.postMessage({ type: "__init", tabId })

    // Register port lifecycle in the same way as handlePortConnect
    port.onMessage.addListener((msg: ExtensionMessage) => {
      sendToDaemon(msg)
    })
    port.onDisconnect.addListener(() => {
      tabPorts.delete(tabId)
      chrome.tabs.get(tabId)
        .then((tab) => {
          sendToDaemon({ type: "tab.disconnect", tabId })
          if (tab.url && isConnectedTab(tabId)) {
            reconnectTab(tabId, tab)
          }
        })
        .catch(() => {
          sendToDaemon({ type: "tab.disconnect", tabId })
          removeConnectedTab(tabId)
        })
    })

    const domain = new URL(tab.url).hostname
    const meta = { url: tab.url, title: tab.title ?? "", domain }
    await addConnectedTab(tabId, meta)

    res.send({ success: true })
  } catch (err) {
    res.send({ success: false, error: (err as Error).message })
  }
}

export default handler
```

### background/messages/disconnect-tab.ts

```typescript
import type { PlasmoMessaging } from "@plasmohq/messaging"

interface DisconnectRequest { tabId: number }
interface DisconnectResponse { success: boolean }

const handler: PlasmoMessaging.MessageHandler<DisconnectRequest, DisconnectResponse> = async (req, res) => {
  const { tabId } = req.body!

  sendToDaemon({ type: "tab.disconnect", tabId })
  tabPorts.get(tabId)?.disconnect()
  tabPorts.delete(tabId)
  await removeConnectedTab(tabId)

  res.send({ success: true })
}

export default handler
```

### background/messages/get-state.ts

```typescript
import type { PlasmoMessaging } from "@plasmohq/messaging"

interface StateResponse {
  connectedTabs: number[]
  tabMeta: Record<number, { url: string; title: string; domain: string }>
  daemonStatus: string
}

const handler: PlasmoMessaging.MessageHandler<void, StateResponse> = async (req, res) => {
  const state = await loadState()
  res.send({
    connectedTabs: state.connectedTabs,
    tabMeta: state.tabMeta,
    daemonStatus: state.daemonStatus
  })
}

export default handler
```

### background/messages/send-chat.ts

```typescript
import type { PlasmoMessaging } from "@plasmohq/messaging"

interface ChatRequest { tabId: number; text: string }
interface ChatResponse { success: boolean }

const handler: PlasmoMessaging.MessageHandler<ChatRequest, ChatResponse> = async (req, res) => {
  const { tabId, text } = req.body!

  // Forward to daemon as a user conversation message
  // See ../integration/SPEC.md Section 1.1 User Chat for protocol definition
  sendToDaemon({
    type: "user.chat",
    tabId,
    text
  } as ExtensionMessage)

  res.send({ success: true })
}

export default handler
```

### Port Handling: No background/ports/tab-bridge.ts

The `background/ports/` directory is NOT used for the `tab-bridge` port. Plasmo's file-based port handlers use a request-response pattern incompatible with our streaming use case. If both a Plasmo port handler AND a raw `chrome.runtime.onConnect` listener exist for the same port name, both fire, causing double-handling.

The `tab-bridge` port is handled exclusively by the raw `chrome.runtime.onConnect` listener in `background/index.ts` (Section 4). Do not create `background/ports/tab-bridge.ts`.

---

## 6. Side Panel UI

File: `src/sidepanel.tsx`

The side panel is a persistent React application that stays open across tab switches and navigation. It is the user's interface to the agent system.

### Communication

The side panel communicates with the service worker via a long-lived port named `"side-panel"` and Plasmo's `sendToBackground` for request-response operations.

```typescript
// Opened on side panel mount
const port = chrome.runtime.connect({ name: "side-panel" })
port.onMessage.addListener((msg: UiUpdate) => {
  // Dispatch to state management
})
```

### Component Structure

```
sidepanel.tsx (root)
├── StatusBar           -- daemon connection state, reconnecting indicator
├── TabConnector        -- list of browser tabs, connect/disconnect buttons
│   └── TabBadge[]      -- per-tab status (connected, idle, active, error)
├── ConfidenceDisplay   -- per-action confidence meters for selected tab
└── AgentConversation   -- chat feed for selected tab
```

### State Management

All UI state is driven by `ui.update` messages from the daemon, delivered via the service worker port. The side panel does not poll or query the daemon directly.

```typescript
interface SidePanelState {
  daemonStatus: "online" | "offline" | "reconnecting"
  connectedTabs: Map<number, TabState>
  selectedTabId: number | null
  conversations: Map<number, ConversationMessage[]>
}

interface TabState {
  tabId: number
  url: string
  title: string
  domain: string
  state: "connected" | "idle" | "active" | "error"
  legoCount: number
  confidences: Map<string, ConfidenceEntry>
}

interface ConfidenceEntry {
  action: string
  confidence: number
  executions: number
  overrides: number
}

interface ConversationMessage {
  role: "agent" | "user" | "system"
  text: string
  timestamp: number
}
```

State is held in React component state (or a lightweight context). It is not synced to `chrome.storage` -- the side panel rebuilds its state from `ui.update` messages on every open. Conversation history is local to the React app's lifetime; closing and reopening the side panel clears it. This is intentional -- the daemon is the source of truth for workflow state, and the side panel is a view.

### ui.update Dispatch

```typescript
function handleUiUpdate(msg: UiUpdate): void {
  switch (msg.target) {
    case "status": {
      const { tabId, state, domain, legoCount } = msg.data as {
        tabId: number; state: string; domain?: string; legoCount?: number
      }
      updateTabState(tabId, { state, domain, legoCount })
      break
    }

    case "confidence": {
      const { tabId, action, confidence, executions, overrides } = msg.data as {
        tabId: number; action: string; confidence: number; executions: number; overrides: number
      }
      updateConfidence(tabId, { action, confidence, executions, overrides })
      break
    }

    case "conversation": {
      const { tabId, role, text } = msg.data as {
        tabId: number; role: string; text: string
      }
      appendMessage(tabId, { role: role as "agent" | "user", text, timestamp: Date.now() })
      break
    }
  }
}
```

### TabConnector Component

Shows all open browser tabs. User clicks "Connect" to activate observation on a tab. The component queries available tabs via `chrome.tabs.query()` and shows connected/disconnected state.

```typescript
function TabConnector({ state, onConnect, onDisconnect }: TabConnectorProps) {
  const [allTabs, setAllTabs] = useState<chrome.tabs.Tab[]>([])

  useEffect(() => {
    chrome.tabs.query({ currentWindow: true }).then(setAllTabs)
    const listener = () => chrome.tabs.query({ currentWindow: true }).then(setAllTabs)
    chrome.tabs.onUpdated.addListener(listener)
    chrome.tabs.onRemoved.addListener(listener)
    chrome.tabs.onCreated.addListener(listener)
    return () => {
      chrome.tabs.onUpdated.removeListener(listener)
      chrome.tabs.onRemoved.removeListener(listener)
      chrome.tabs.onCreated.removeListener(listener)
    }
  }, [])

  return (
    <div>
      {allTabs.map(tab => {
        const connected = state.connectedTabs.has(tab.id!)
        return (
          <div key={tab.id}>
            <TabBadge tab={tab} connected={connected} />
            <button onClick={() => connected ? onDisconnect(tab.id!) : onConnect(tab.id!)}>
              {connected ? "Disconnect" : "Connect"}
            </button>
          </div>
        )
      })}
    </div>
  )
}
```

### ConfidenceDisplay Component

Per-action confidence meters for the currently selected tab. Shows the daemon's learned accuracy for each action type on the current domain.

```typescript
function ConfidenceDisplay({ confidences }: { confidences: Map<string, ConfidenceEntry> }) {
  return (
    <div>
      {Array.from(confidences.entries()).map(([action, entry]) => (
        <div key={action}>
          <span>{action}</span>
          <div style={{ width: `${entry.confidence * 100}%` }} />
          <span>{Math.round(entry.confidence * 100)}%</span>
          <span>{entry.executions} runs, {entry.overrides} corrections</span>
        </div>
      ))}
    </div>
  )
}
```

### AgentConversation Component

Chat-like feed of agent messages for the selected tab. The user can type messages that are sent to the daemon via the `send-chat` message handler.

```typescript
function AgentConversation({ messages, onSend }: ConversationProps) {
  const [input, setInput] = useState("")

  return (
    <div>
      <div>
        {messages.map((msg, i) => (
          <div key={i} data-role={msg.role}>
            <span>{msg.role}</span>
            <p>{msg.text}</p>
          </div>
        ))}
      </div>
      <form onSubmit={(e) => {
        e.preventDefault()
        if (input.trim()) {
          onSend(input.trim())
          setInput("")
        }
      }}>
        <input value={input} onChange={(e) => setInput(e.target.value)} />
        <button type="submit">Send</button>
      </form>
    </div>
  )
}
```

---

## 7. Permissions Manifest

Every permission with justification. This is the minimum viable set for the extension to function. Chrome Web Store review scrutinizes permissions -- each must be defensible.

### Required Permissions

| Permission | Justification | Store Review Note |
|------------|---------------|-------------------|
| `sidePanel` | Core UI. The agent conversation and tab connector live in the side panel. | Standard for assistant-type extensions. |
| `storage` | Persist connected tab state across service worker restarts and extension updates. Required for reliability. | Standard, no review concern. |
| `tabs` | Query all open tabs to populate the tab connector UI. Access `url`, `title`, `favIconUrl` for display. Detect tab close, navigation, activation. | Justify with: "Shows available tabs for user to select." |
| `activeTab` | Execute content scripts on user-selected tabs. Grants temporary host permission on the tab the user explicitly connects. | Lower friction than broad host_permissions for initial injection. |
| `scripting` | Programmatically inject the content script via `chrome.scripting.executeScript()` when the user connects a tab after page load. | Required companion to `activeTab`. |
| `webRequest` | Observe network requests per tab (read-only) to send `net.request`/`net.response` to the daemon. No modification, no blocking. | Justify with: "Monitors network activity to detect page state changes." MV3 webRequest is read-only, reducing review concern. |
| `webNavigation` | Detect full-page navigations via `chrome.webNavigation.onCommitted` to manage content script lifecycle. Distinguish SPA navigation from full navigation. | Standard for extensions that manage content scripts. |
| `nativeMessaging` | Bootstrap daemon port discovery via `chrome.runtime.connectNative()`. Keepalive mechanism to prevent service worker termination during active operation. | Required. Same pattern as 1Password, Bitwarden. |
| `alarms` | Heartbeat fallback for service worker keepalive. Periodic re-bootstrap of Native Messaging if the native port disconnects. | Standard, no review concern. |

### host_permissions

```json
"host_permissions": ["<all_urls>"]
```

Justification: The extension must be able to inject content scripts and observe network requests on any website the user connects. The user explicitly chooses which tabs to connect -- the extension does not automatically access any page. `<all_urls>` is required because `activeTab` alone does not support `chrome.webRequest` filtering and does not persist across service worker restarts.

Store review strategy: Emphasize that the extension only activates on user-selected tabs. The side panel connector UI makes this explicit -- no background scraping or automatic injection. Include screenshots of the tab connector showing the explicit user gesture.

### Permissions NOT Requested

| Permission | Why Not |
|------------|---------|
| `cookies` | Not needed. Authentication uses the user's existing sessions. |
| `history` | Not needed. Tab connector uses live tab state. |
| `bookmarks` | Not relevant. |
| `downloads` | Not relevant. |
| `clipboardRead`/`clipboardWrite` | Not needed in v1. |
| `debugger` | Would provide CDP access but triggers a persistent warning bar. Unacceptable UX. |
| `declarativeNetRequest` | We observe, not modify. `webRequest` read-only suffices. |
| `management` | Not needed. Extension does not manage other extensions. |

---

## 8. Constants and Configuration

File: `src/lib/constants.ts`

```typescript
// Content script buffer
export const BUFFER_CAPACITY = 64
export const PRESSURE_THRESHOLD = 16

// WebSocket backpressure
export const WS_BUFFERED_AMOUNT_PAUSE = 1_048_576   // 1MB
export const WS_BUFFERED_AMOUNT_RESUME = 524_288     // 512KB

// Reconnection backoff
export const RECONNECT_MAX_BACKOFF_MS = 30_000
export const RECONNECT_JITTER_MAX_MS = 1_000

// Native Messaging
export const NATIVE_HOST_NAME = "com.phalanx.bridge"
export const NATIVE_BOOTSTRAP_TIMEOUT_MS = 5_000

// Content script observation
export const DEFAULT_SNAPSHOT_SELECTOR = "body"
export const SCROLL_DEBOUNCE_MS = 300
export const SCROLL_THRESHOLD_PX = 200

// Daemon offline display threshold
export const DAEMON_OFFLINE_DISPLAY_MS = 60_000

// Action step defaults
export const DEFAULT_WAIT_TIMEOUT_MS = 5_000
export const DEFAULT_NETWORK_WAIT_TIMEOUT_MS = 10_000
```

---

## 9. Build and Development

### Development

```bash
pnpm dev
```

Builds to `build/chrome-mv3-dev`. Load at `chrome://extensions` with Developer mode enabled. Plasmo provides HMR for the side panel and content scripts.

During development, the service worker stays alive (Plasmo dev mode prevents termination). Test service worker restart behavior by toggling the extension off and on at `chrome://extensions`.

### Production Build

```bash
pnpm build
pnpm build:zip  # Creates store-ready zip
```

Builds to `build/chrome-mv3-prod`.

### Testing Strategy

See `../integration/SPEC.md` Section 6 for the full testing boundary. Extension-specific tests:

**Unit testable (vitest, no browser):**
- Wire protocol type serialization/deserialization
- Mutation summarizer: given mock `MutationRecord` arrays, verify output format
- CSS selector generator: given mock DOM elements, verify selector priority cascade
- Buffer management: verify drop-oldest at capacity, flush behavior
- State persistence: verify `loadState`/`persistState` round-trip

**Integration testable (with mock WebSocket server):**
- Service worker WebSocket lifecycle: connect, message exchange, reconnection on close
- Content script message forwarding: inject into test page, verify messages reach mock server
- Action execution: mock server sends `action.execute`, verify content script executes and returns `action.result`
- Native Messaging bootstrap: mock native host, verify port discovery

**Requires real browser (Playwright or manual):**
- Service worker termination and restart
- Content script survival across SPA navigation
- Side panel state rebuild on reconnection
- Full backpressure chain: high-frequency DOM mutations through to WebSocket bufferedAmount monitoring

---

## 10. Implementation Sequence

Aligned with `../integration/SPEC.md` Section 5.3 (Extension-Only Milestones):

| Phase | Scope | Deliverable |
|-------|-------|-------------|
| E1 (week 1) | Service worker scaffold | WebSocket connect to mock server. Message send/receive. `chrome.storage.session` state persistence. |
| E2 (week 1) | Native Messaging bootstrap | `connectNative()` reads daemon port from lockfile. Keepalive port management. Fallback to hardcoded port. |
| E3 (week 2-3) | Content script | MutationObserver + mutation summarizer. All 16 action step ops. User action detection. Port communication with service worker. Buffer management. |
| E4 (week 2-3) | Side panel UI | React components: TabConnector, ConfidenceDisplay, AgentConversation, StatusBar. Receives `ui.update` messages. |
| E5 (week 3) | Flow control | Buffer depth tracking, `flow.pressure` emission, `flow.throttle` response (frame skip adjustment). |
| E6 (week 4) | Reconnection | State persist on disconnect, backoff reconnect, tab re-registration, DOM snapshot resend, local storage backup for extension updates. |
