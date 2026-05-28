// Service worker entry -- ALL listeners registered synchronously at top level.
// Never register chrome.* listeners inside async callbacks.

import { initWebSocket, sendToDaemon } from "~lib/ws-manager"
import { bootstrapNativeMessaging } from "~lib/native-bootstrap"
import { routeDaemonMessage } from "~lib/router"
import {
  loadState,
  persistState,
  addConnectedTab,
  removeConnectedTab,
  isConnectedTab,
  type BridgeState,
} from "~lib/state"
import {
  setTabPort,
  removeTabPort,
  hasTabPort,
  getTabPort,
  disconnectTabPort,
  setSidePanelPort,
} from "~lib/router"
import { startNetworkObserver } from "~lib/network-observer"
import {
  handleEvaluate,
  handleWaitForNetwork,
} from "~lib/sw-action-handlers"
import type { DaemonMessage, ExtensionMessage, UiUpdate } from "~lib/types"

// -- SYNCHRONOUS EVENT LISTENER REGISTRATION --

chrome.tabs.onRemoved.addListener(handleTabRemoved)
chrome.tabs.onUpdated.addListener(handleTabUpdated)
chrome.webNavigation.onCommitted.addListener(handleNavigation)
chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true })
chrome.runtime.onConnect.addListener(handlePortConnect)
chrome.runtime.onInstalled.addListener(handleInstalled)
chrome.runtime.onStartup.addListener(handleStartup)
chrome.alarms.onAlarm.addListener(handleAlarm)

// Network observer -- registers chrome.webRequest listeners synchronously.
// Filters to connected tabs only; sends net.request/net.response through
// the same sendToDaemon path as content script messages.
startNetworkObserver(
  (tabId: number, msg: ExtensionMessage) => sendToDaemon(msg),
  (tabId: number) => hasTabPort(tabId),
)

// -- ASYNC INITIALIZATION (after all listeners registered) --
initialize()

async function initialize(): Promise<void> {
  const state = await loadState()

  const wsUrl = await bootstrapNativeMessaging()
  if (!wsUrl) {
    await persistState({ ...state, daemonStatus: "offline" })
    return
  }

  initWebSocket(wsUrl, {
    onMessage: (msg: DaemonMessage) => routeDaemonMessage(msg),
    onClose: () => handleWsClose(),
    onOpen: () => handleWsOpen(),
  })

  for (const tabId of state.connectedTabs) {
    try {
      const tab = await chrome.tabs.get(tabId)
      if (tab) reconnectTab(tabId, tab)
    } catch {
      await removeConnectedTab(tabId)
    }
  }
}

// -- TAB LIFECYCLE --

function handleTabRemoved(tabId: number): void {
  if (hasTabPort(tabId)) {
    sendToDaemon({ type: "tab.disconnect", tabId })
    disconnectTabPort(tabId)
    removeConnectedTab(tabId)
  }
}

async function handleTabUpdated(
  tabId: number,
  changeInfo: chrome.tabs.TabChangeInfo,
  tab: chrome.tabs.Tab,
): Promise<void> {
  const state = await loadState()
  if (!isConnectedTab(tabId, state)) return

  if (changeInfo.url && tab.url) {
    sendToDaemon({
      type: "tab.navigate",
      tabId,
      url: tab.url,
      title: tab.title ?? "",
    })
    const domain = new URL(tab.url).hostname
    await addConnectedTab(tabId, { url: tab.url, title: tab.title ?? "", domain })
  }
}

function handleNavigation(
  details: chrome.webNavigation.WebNavigationCommittedDetails,
): void {
  // Top frame only. Port lifecycle handles reconnection -- see SPEC.md Section 4.
  if (details.frameId !== 0) return
}

// -- PORT MANAGEMENT --

function handlePortConnect(port: chrome.runtime.Port): void {
  if (port.name === "side-panel") {
    setSidePanelPort(port)
    port.onDisconnect.addListener(() => {
      setSidePanelPort(null)
    })
    return
  }

  if (port.name === "tab-bridge") {
    const senderTabId = port.sender?.tab?.id
    if (senderTabId === undefined) return

    // Tell the content script its tab ID, then register the port
    port.postMessage({ type: "__init", tabId: senderTabId })
    setTabPort(senderTabId, port)

    // Content script messages: internal messages are handled by the service worker,
    // everything else is forwarded to the daemon over WebSocket.
    port.onMessage.addListener((msg: ExtensionMessage & { type: string }) => {
      if (msg.type === "__evaluate") {
        handleEvaluate(msg as unknown as { type: string; expression: string }, senderTabId, port)
        return
      }
      if (msg.type === "__waitForNetwork") {
        handleWaitForNetwork(msg as unknown as { type: string; urlPattern: string; timeoutMs: number }, senderTabId, port)
        return
      }
      sendToDaemon(msg)
    })

    port.onDisconnect.addListener(() => {
      removeTabPort(senderTabId)
      // Check if the tab still exists -- if so, this was a navigation reload
      // and reconnectTab will re-inject the content script
      chrome.tabs
        .get(senderTabId)
        .then(async (tab) => {
          sendToDaemon({ type: "tab.disconnect", tabId: senderTabId })
          const state = await loadState()
          if (tab.url && isConnectedTab(senderTabId, state)) {
            reconnectTab(senderTabId, tab)
          }
        })
        .catch(() => {
          // Tab no longer exists (closed)
          sendToDaemon({ type: "tab.disconnect", tabId: senderTabId })
          removeConnectedTab(senderTabId)
        })
    })
    return
  }
}

// -- RECONNECT TAB --

async function reconnectTab(
  tabId: number,
  tab: chrome.tabs.Tab,
): Promise<void> {
  try {
    await chrome.scripting.executeScript({
      target: { tabId },
      files: ["contents/bridge.js"],
    })

    const port = chrome.tabs.connect(tabId, { name: "tab-bridge" })
    setTabPort(tabId, port)
    port.postMessage({ type: "__init", tabId })

    port.onMessage.addListener((msg: ExtensionMessage & { type: string }) => {
      if (msg.type === "__evaluate") {
        handleEvaluate(msg as unknown as { type: string; expression: string }, tabId, port)
        return
      }
      if (msg.type === "__waitForNetwork") {
        handleWaitForNetwork(msg as unknown as { type: string; urlPattern: string; timeoutMs: number }, tabId, port)
        return
      }
      sendToDaemon(msg)
    })

    port.onDisconnect.addListener(() => {
      removeTabPort(tabId)
      chrome.tabs
        .get(tabId)
        .then(async (t) => {
          sendToDaemon({ type: "tab.disconnect", tabId })
          const state = await loadState()
          if (t.url && isConnectedTab(tabId, state)) reconnectTab(tabId, t)
        })
        .catch(() => {
          sendToDaemon({ type: "tab.disconnect", tabId })
          removeConnectedTab(tabId)
        })
    })

    if (tab.url) {
      const domain = new URL(tab.url).hostname
      await addConnectedTab(tabId, {
        url: tab.url,
        title: tab.title ?? "",
        domain,
      })
    }
  } catch {
    // Injection failed (e.g., chrome:// page, PDF)
    await removeConnectedTab(tabId)
  }
}

// -- WEBSOCKET LIFECYCLE --

async function handleWsClose(): Promise<void> {
  const state = await loadState()
  state.daemonStatus = "reconnecting"
  await persistState(state)

  const update: UiUpdate = {
    type: "ui.update",
    target: "status",
    data: { state: "reconnecting" },
  }
  // Synthesize a ui.update and route through the router which holds the side panel port
  routeDaemonMessage(update)
}

async function handleWsOpen(): Promise<void> {
  const state = await loadState()
  state.daemonStatus = "online"
  await persistState(state)

  // Resend tab.connect for all active tabs -- "fresh start" reconnection
  for (const tabId of state.connectedTabs) {
    try {
      const tab = await chrome.tabs.get(tabId)
      if (!tab.url) continue
      const domain = new URL(tab.url).hostname

      sendToDaemon({
        type: "tab.connect",
        tabId,
        sessionId: chrome.runtime.id,
        url: tab.url,
        title: tab.title ?? "",
        domain,
      })

      await addConnectedTab(tabId, {
        url: tab.url,
        title: tab.title ?? "",
        domain,
      })

      const port = getTabPort(tabId)
      if (port) {
        port.postMessage({ type: "__resend_snapshot" })
      } else {
        reconnectTab(tabId, tab)
      }
    } catch {
      await removeConnectedTab(tabId)
    }
  }

  const update: UiUpdate = {
    type: "ui.update",
    target: "status",
    data: { state: "connected" },
  }
  routeDaemonMessage(update)
}

// -- INSTALL / STARTUP --

async function handleInstalled(): Promise<void> {
  // Session storage is cleared on ALL extension restarts.
  // Restore from local storage backup.
  const backup = await chrome.storage.local.get([
    "connectedTabsBackup",
    "tabMetaBackup",
  ])
  if (backup.connectedTabsBackup) {
    await persistState({
      connectedTabs: backup.connectedTabsBackup,
      tabMeta: backup.tabMetaBackup ?? {},
      daemonStatus: "offline",
      wsUrl: null,
    })
  }
}

async function handleStartup(): Promise<void> {
  const backup = await chrome.storage.local.get([
    "connectedTabsBackup",
    "tabMetaBackup",
  ])
  if (backup.connectedTabsBackup) {
    await persistState({
      connectedTabs: backup.connectedTabsBackup,
      tabMeta: backup.tabMetaBackup ?? {},
      daemonStatus: "offline",
      wsUrl: null,
    })
  }
  await initialize()
}

function handleAlarm(alarm: chrome.alarms.Alarm): void {
  if (alarm.name === "native-reconnect") {
    bootstrapNativeMessaging()
  }
}
