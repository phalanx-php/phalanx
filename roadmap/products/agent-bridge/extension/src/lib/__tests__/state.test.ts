import { describe, it, expect, vi, beforeEach, afterEach } from "vitest"

// Mock chrome.storage APIs before importing state module
const sessionStore: Record<string, unknown> = {}
const localStore: Record<string, unknown> = {}

const chromeMock = {
  storage: {
    session: {
      get: async (key: string) => {
        const val = sessionStore[key]
        return { [key]: val !== undefined ? JSON.parse(JSON.stringify(val)) : undefined }
      },
      set: async (items: Record<string, unknown>) => {
        for (const [k, v] of Object.entries(items)) {
          sessionStore[k] = JSON.parse(JSON.stringify(v))
        }
      },
    },
    local: {
      get: async (keys: string[]) => {
        const result: Record<string, unknown> = {}
        for (const key of keys) {
          if (key in localStore) result[key] = JSON.parse(JSON.stringify(localStore[key]))
        }
        return result
      },
      set: async (items: Record<string, unknown>) => {
        for (const [k, v] of Object.entries(items)) {
          localStore[k] = JSON.parse(JSON.stringify(v))
        }
      },
    },
  },
}

vi.stubGlobal("chrome", chromeMock)

import {
  loadState,
  persistState,
  addConnectedTab,
  removeConnectedTab,
  isConnectedTab,
  type BridgeState,
} from "~/lib/state"

describe("state persistence", () => {
  beforeEach(() => {
    for (const key of Object.keys(sessionStore)) delete sessionStore[key]
    for (const key of Object.keys(localStore)) delete localStore[key]
  })

  it("returns default state when storage is empty", async () => {
    const state = await loadState()
    expect(state).toEqual({
      connectedTabs: [],
      tabMeta: {},
      daemonStatus: "offline",
      wsUrl: null,
    })
  })

  it("round-trips state through persistState/loadState", async () => {
    const state: BridgeState = {
      connectedTabs: [1, 2, 3],
      tabMeta: {
        1: { url: "https://a.com", title: "A", domain: "a.com" },
        2: { url: "https://b.com", title: "B", domain: "b.com" },
        3: { url: "https://c.com", title: "C", domain: "c.com" },
      },
      daemonStatus: "online",
      wsUrl: "ws://localhost:9078/bridge",
    }

    await persistState(state)
    const loaded = await loadState()

    expect(loaded).toEqual(state)
  })

  it("addConnectedTab adds tab and persists", async () => {
    await addConnectedTab(42, {
      url: "https://example.com",
      title: "Example",
      domain: "example.com",
    })

    const state = await loadState()
    expect(state.connectedTabs).toContain(42)
    expect(state.tabMeta[42]).toEqual({
      url: "https://example.com",
      title: "Example",
      domain: "example.com",
    })
  })

  it("addConnectedTab does not duplicate existing tabId", async () => {
    await addConnectedTab(42, {
      url: "https://example.com",
      title: "Example",
      domain: "example.com",
    })
    await addConnectedTab(42, {
      url: "https://example.com/page",
      title: "Page",
      domain: "example.com",
    })

    const state = await loadState()
    expect(state.connectedTabs.filter((id) => id === 42)).toHaveLength(1)
    expect(state.tabMeta[42].url).toBe("https://example.com/page")
  })

  it("addConnectedTab creates local storage backup", async () => {
    await addConnectedTab(42, {
      url: "https://example.com",
      title: "Example",
      domain: "example.com",
    })

    expect(localStore.connectedTabsBackup).toEqual([42])
    expect(localStore.tabMetaBackup).toEqual({
      42: { url: "https://example.com", title: "Example", domain: "example.com" },
    })
  })

  it("removeConnectedTab removes tab and persists", async () => {
    await addConnectedTab(42, {
      url: "https://example.com",
      title: "Example",
      domain: "example.com",
    })
    await addConnectedTab(43, {
      url: "https://other.com",
      title: "Other",
      domain: "other.com",
    })

    await removeConnectedTab(42)

    const state = await loadState()
    expect(state.connectedTabs).not.toContain(42)
    expect(state.connectedTabs).toContain(43)
    expect(state.tabMeta[42]).toBeUndefined()
    expect(state.tabMeta[43]).toBeDefined()
  })

  it("removeConnectedTab updates local storage backup", async () => {
    await addConnectedTab(42, {
      url: "https://example.com",
      title: "Example",
      domain: "example.com",
    })
    await removeConnectedTab(42)

    expect(localStore.connectedTabsBackup).toEqual([])
    expect(localStore.tabMetaBackup).toEqual({})
  })

  it("isConnectedTab returns true for connected tabs", async () => {
    await addConnectedTab(42, {
      url: "https://example.com",
      title: "Example",
      domain: "example.com",
    })

    const state = await loadState()
    expect(isConnectedTab(42, state)).toBe(true)
    expect(isConnectedTab(99, state)).toBe(false)
  })

  it("handles removing a tab that was never added", async () => {
    const before = await loadState()
    const countBefore = before.connectedTabs.length
    await removeConnectedTab(999)
    const after = await loadState()
    expect(after.connectedTabs.length).toBe(countBefore)
  })

  it("preserves daemonStatus through mutations", async () => {
    await persistState({
      connectedTabs: [],
      tabMeta: {},
      daemonStatus: "reconnecting",
      wsUrl: "ws://localhost:9078/bridge",
    })

    await addConnectedTab(42, {
      url: "https://example.com",
      title: "Example",
      domain: "example.com",
    })

    const state = await loadState()
    expect(state.daemonStatus).toBe("reconnecting")
    expect(state.wsUrl).toBe("ws://localhost:9078/bridge")
  })
})
