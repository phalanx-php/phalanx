import type { DaemonMessage } from "./types"

// Content script ports, keyed by tabId
const tabPorts = new Map<number, chrome.runtime.Port>()

// Side panel port
let sidePanelPort: chrome.runtime.Port | null = null

export function routeDaemonMessage(msg: DaemonMessage): void {
  switch (msg.type) {
    case "action.execute":
    case "action.cancel":
    case "dom.request":
    case "flow.throttle":
    case "flow.resume": {
      const tabId = (msg as { tabId: number }).tabId
      const port = tabPorts.get(tabId)
      if (port) {
        port.postMessage(msg)
      } else {
        console.warn(`[router] No port for tab ${tabId}, dropping ${msg.type}`)
      }
      break
    }

    case "ui.update": {
      if (sidePanelPort) {
        sidePanelPort.postMessage(msg)
      } else {
        console.warn("[router] No side panel port, dropping ui.update")
      }
      break
    }

    default:
      // Unknown message type -- silently ignore per protocol evolution rule
      break
  }
}

export function setTabPort(tabId: number, port: chrome.runtime.Port): void {
  tabPorts.set(tabId, port)
}

export function removeTabPort(tabId: number): void {
  tabPorts.delete(tabId)
}

export function getTabPort(tabId: number): chrome.runtime.Port | undefined {
  return tabPorts.get(tabId)
}

export function hasTabPort(tabId: number): boolean {
  return tabPorts.has(tabId)
}

export function setSidePanelPort(port: chrome.runtime.Port | null): void {
  sidePanelPort = port
}

export function disconnectTabPort(tabId: number): void {
  const port = tabPorts.get(tabId)
  if (port) {
    port.disconnect()
    tabPorts.delete(tabId)
  }
}
