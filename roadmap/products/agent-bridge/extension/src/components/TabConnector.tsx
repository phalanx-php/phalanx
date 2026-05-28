import { useState, useEffect, useCallback } from "react"
import { sendToBackground } from "@plasmohq/messaging"
import { TabBadge } from "./TabBadge"

interface TabInfo {
  id: number
  title: string
  url: string
  favIconUrl?: string
}

interface BridgeState {
  connectedTabs: number[]
}

export function TabConnector() {
  const [tabs, setTabs] = useState<TabInfo[]>([])
  const [connectedTabs, setConnectedTabs] = useState<Set<number>>(new Set())
  const [pending, setPending] = useState<Set<number>>(new Set())

  const refreshTabs = useCallback(async () => {
    const allTabs = await chrome.tabs.query({})
    const filtered: TabInfo[] = allTabs
      .filter((t) => t.id !== undefined && t.url && !t.url.startsWith("chrome://"))
      .map((t) => ({
        id: t.id!,
        title: t.title ?? "Untitled",
        url: t.url!,
        favIconUrl: t.favIconUrl,
      }))
    setTabs(filtered)
  }, [])

  const refreshState = useCallback(async () => {
    try {
      const result = await chrome.storage.session.get("bridge_state")
      const state = result.bridge_state as BridgeState | undefined
      setConnectedTabs(new Set(state?.connectedTabs ?? []))
    } catch {
      // Storage not available yet
    }
  }, [])

  useEffect(() => {
    refreshTabs()
    refreshState()

    // Refresh when tabs change
    const onUpdated = () => { refreshTabs(); refreshState() }
    const onRemoved = () => { refreshTabs(); refreshState() }
    const onCreated = () => refreshTabs()

    chrome.tabs.onUpdated.addListener(onUpdated)
    chrome.tabs.onRemoved.addListener(onRemoved)
    chrome.tabs.onCreated.addListener(onCreated)

    // Refresh when bridge state changes
    const onStorageChanged = (
      changes: { [key: string]: chrome.storage.StorageChange },
      area: string,
    ) => {
      if (area === "session" && changes.bridge_state) {
        refreshState()
      }
    }
    chrome.storage.onChanged.addListener(onStorageChanged)

    return () => {
      chrome.tabs.onUpdated.removeListener(onUpdated)
      chrome.tabs.onRemoved.removeListener(onRemoved)
      chrome.tabs.onCreated.removeListener(onCreated)
      chrome.storage.onChanged.removeListener(onStorageChanged)
    }
  }, [refreshTabs, refreshState])

  async function handleConnect(tabId: number): Promise<void> {
    setPending((prev) => new Set(prev).add(tabId))
    try {
      const resp = await sendToBackground({
        name: "connect-tab",
        body: { tabId },
      })
      if (resp.success) {
        setConnectedTabs((prev) => new Set(prev).add(tabId))
      }
    } finally {
      setPending((prev) => {
        const next = new Set(prev)
        next.delete(tabId)
        return next
      })
    }
  }

  async function handleDisconnect(tabId: number): Promise<void> {
    setPending((prev) => new Set(prev).add(tabId))
    try {
      await sendToBackground({
        name: "disconnect-tab",
        body: { tabId },
      })
      setConnectedTabs((prev) => {
        const next = new Set(prev)
        next.delete(tabId)
        return next
      })
    } finally {
      setPending((prev) => {
        const next = new Set(prev)
        next.delete(tabId)
        return next
      })
    }
  }

  if (tabs.length === 0) {
    return (
      <p style={{ color: "#6b7280", fontSize: "13px" }}>No connectable tabs.</p>
    )
  }

  return (
    <div>
      <h3 style={{ fontSize: "13px", margin: "0 0 8px 0", color: "#374151" }}>
        Tabs
      </h3>
      <ul style={{ listStyle: "none", padding: 0, margin: 0 }}>
        {tabs.map((tab) => {
          const isConnected = connectedTabs.has(tab.id)
          const isPending = pending.has(tab.id)

          return (
            <li
              key={tab.id}
              style={{
                display: "flex",
                alignItems: "center",
                gap: "8px",
                padding: "6px 0",
                borderBottom: "1px solid #e5e7eb",
                fontSize: "12px",
              }}
            >
              {tab.favIconUrl && (
                <img
                  src={tab.favIconUrl}
                  alt=""
                  style={{ width: "16px", height: "16px" }}
                />
              )}
              <span
                style={{
                  flex: 1,
                  overflow: "hidden",
                  textOverflow: "ellipsis",
                  whiteSpace: "nowrap",
                }}
                title={tab.url}
              >
                {tab.title}
              </span>
              <TabBadge connected={isConnected} />
              <button
                disabled={isPending}
                onClick={() =>
                  isConnected
                    ? handleDisconnect(tab.id)
                    : handleConnect(tab.id)
                }
                style={{
                  padding: "3px 8px",
                  fontSize: "11px",
                  borderRadius: "4px",
                  border: "1px solid #d1d5db",
                  background: isConnected ? "#fee2e2" : "#dbeafe",
                  color: isConnected ? "#991b1b" : "#1e40af",
                  cursor: isPending ? "wait" : "pointer",
                  opacity: isPending ? 0.6 : 1,
                }}
              >
                {isPending
                  ? "..."
                  : isConnected
                    ? "Disconnect"
                    : "Connect"}
              </button>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
