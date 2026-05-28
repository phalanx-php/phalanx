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
  wsUrl: null,
}

// Serialization queue prevents interleaved load-modify-persist races.
// Two simultaneous removeConnectedTab calls would each load the same snapshot,
// apply their filter, and persist -- the second write overwrites the first removal.
// The queue ensures mutations execute sequentially.
let mutationQueue = Promise.resolve()

function serializeMutation(fn: () => Promise<void>): Promise<void> {
  mutationQueue = mutationQueue.then(fn, fn)
  return mutationQueue
}

export async function loadState(): Promise<BridgeState> {
  const result = await chrome.storage.session.get(STORAGE_KEY)
  return (result[STORAGE_KEY] as BridgeState) ?? { ...defaultState }
}

export async function persistState(state: BridgeState): Promise<void> {
  await chrome.storage.session.set({ [STORAGE_KEY]: state })
}

export function addConnectedTab(
  tabId: number,
  meta: { url: string; title: string; domain: string },
): Promise<void> {
  return serializeMutation(async () => {
    const state = await loadState()
    if (!state.connectedTabs.includes(tabId)) {
      state.connectedTabs.push(tabId)
    }
    state.tabMeta[tabId] = meta
    await persistState(state)

    await chrome.storage.local.set({
      connectedTabsBackup: state.connectedTabs,
      tabMetaBackup: state.tabMeta,
    })
  })
}

export function removeConnectedTab(tabId: number): Promise<void> {
  return serializeMutation(async () => {
    const state = await loadState()
    state.connectedTabs = state.connectedTabs.filter((id) => id !== tabId)
    delete state.tabMeta[tabId]
    await persistState(state)
    await chrome.storage.local.set({
      connectedTabsBackup: state.connectedTabs,
      tabMetaBackup: state.tabMeta,
    })
  })
}

export function isConnectedTab(
  tabId: number,
  state: BridgeState,
): boolean {
  return state.connectedTabs.includes(tabId)
}
