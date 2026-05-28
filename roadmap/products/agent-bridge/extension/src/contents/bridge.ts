// Content script -- injected per connected tab.
// Runs in Chrome's ISOLATED world (shares DOM, not JS globals with the page).
// Communicates with the service worker via a long-lived port named "tab-bridge".

import type { PlasmoCSConfig } from "plasmo"
import type {
  ExtensionMessage,
  ActionExecute,
  ActionCancel,
  DomRequestCmd,
} from "~lib/types"
import {
  OUTBOUND_BUFFER_SIZE,
  OUTBOUND_BUFFER_PRESSURE_THRESHOLD,
} from "~lib/constants"
import { startObserving, sendInitialSnapshot } from "~lib/dom-observer"
import { startUserActionListeners } from "~lib/user-observer"
import { executeAction, cancelledActions } from "~lib/action-executor"
import { handleDomRequest } from "~lib/dom-responder"

export const config: PlasmoCSConfig = {
  matches: ["<all_urls>"],
  run_at: "document_idle",
}

// Port to the service worker. Lifetime matches the content script's document.
let port: chrome.runtime.Port | null = null
let tabId: number | null = null

// Cleanup functions for DOM/user observers -- called on disconnect
let stopDomObserver: (() => void) | null = null
let stopUserObserver: (() => void) | null = null

// -- OUTBOUND BUFFER --
// Messages queue here before being sent through the port. Drop-oldest on overflow.
// When depth crosses the pressure threshold, we notify the daemon so it can
// apply backpressure upstream (flow.throttle to the stream pipeline).
let outboundBuffer: ExtensionMessage[] = []
let pressureSent = false

function bufferSend(msg: ExtensionMessage): void {
  if (!port || tabId === null) return

  outboundBuffer.push(msg)

  // Drop oldest when buffer overflows -- newer data is more valuable
  if (outboundBuffer.length > OUTBOUND_BUFFER_SIZE) {
    outboundBuffer.shift()
  }

  // Signal backpressure when buffer depth crosses the threshold
  if (
    !pressureSent &&
    outboundBuffer.length >= OUTBOUND_BUFFER_PRESSURE_THRESHOLD
  ) {
    pressureSent = true
    port.postMessage({
      type: "flow.pressure",
      tabId,
      bufferDepth: outboundBuffer.length,
    })
  }

  flushBuffer()
}

function flushBuffer(): void {
  if (!port) return

  while (outboundBuffer.length > 0) {
    const msg = outboundBuffer.shift()!
    port.postMessage(msg)
  }

  // Reset pressure flag when buffer drains
  if (outboundBuffer.length === 0) {
    pressureSent = false
  }
}

// -- PORT LIFECYCLE --

function connectToServiceWorker(): void {
  port = chrome.runtime.connect({ name: "tab-bridge" })

  port.onMessage.addListener(handleMessage)
  port.onDisconnect.addListener(handleDisconnect)
}

function handleMessage(msg: { type: string; [key: string]: unknown }): void {
  switch (msg.type) {
    case "__init":
      // Service worker tells us our tab ID -- we can't determine it ourselves
      tabId = msg.tabId as number
      sendTabConnect()
      sendInitialSnapshot(bufferSend, tabId)
      startObservers()
      break

    case "__resend_snapshot":
      // Service worker requests a fresh snapshot after WebSocket reconnection
      if (tabId !== null) {
        sendInitialSnapshot(bufferSend, tabId)
      }
      break

    // Daemon commands routed through the service worker
    case "action.execute": {
      const cmd = msg as unknown as ActionExecute
      executeAction(cmd.steps, cmd.actionId, tabId!, bufferSend, port!)
      break
    }

    case "action.cancel": {
      const cancel = msg as unknown as ActionCancel
      cancelledActions.add(cancel.actionId)
      break
    }

    case "dom.request": {
      const req = msg as unknown as DomRequestCmd
      handleDomRequest(req, tabId!, bufferSend)
      break
    }

    // Flow control -- Phase 6 stubs
    case "flow.throttle":
      console.log("[bridge] flow.throttle received (stub)", msg)
      break

    case "flow.resume":
      console.log("[bridge] flow.resume received (stub)", msg)
      break

    default:
      // Unknown message type -- silently ignore per protocol evolution rule
      break
  }
}

function handleDisconnect(): void {
  stopObservers()
  port = null
  tabId = null
  outboundBuffer = []
  pressureSent = false
  // Service worker will handle reconnection if the tab is still connected.
  // Content script cleans up and waits for re-injection.
}

function startObservers(): void {
  if (tabId === null) return
  stopDomObserver = startObserving(bufferSend, tabId)
  stopUserObserver = startUserActionListeners(bufferSend, tabId)
}

function stopObservers(): void {
  stopDomObserver?.()
  stopDomObserver = null
  stopUserObserver?.()
  stopUserObserver = null
}

function sendTabConnect(): void {
  if (!port || tabId === null) return

  bufferSend({
    type: "tab.connect",
    tabId,
    sessionId: chrome.runtime.id,
    url: location.href,
    title: document.title,
    domain: location.hostname,
  })
}

// Wire up immediately on injection
connectToServiceWorker()
