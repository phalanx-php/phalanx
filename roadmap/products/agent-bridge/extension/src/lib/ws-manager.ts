import type { ExtensionMessage, DaemonMessage } from "./types"
import {
  WS_RECONNECT_BASE_MS,
  WS_RECONNECT_MAX_MS,
  WS_RECONNECT_JITTER_MS,
  BUFFERED_AMOUNT_PAUSE,
  BUFFERED_AMOUNT_RESUME,
  OUTBOUND_BUFFER_SIZE,
} from "./constants"

export type WsState = "disconnected" | "connecting" | "connected" | "reconnecting"

interface WsCallbacks {
  onMessage: (msg: DaemonMessage) => void
  onClose: () => void
  onOpen: () => void
}

let ws: WebSocket | null = null
let reconnectAttempt = 0
let callbacks: WsCallbacks | null = null
let paused = false
let sendQueue: string[] = []
let currentUrl: string | null = null
let wsState: WsState = "disconnected"

export function getWsState(): WsState {
  return wsState
}

export function initWebSocket(url: string, cbs: WsCallbacks): void {
  callbacks = cbs
  currentUrl = url
  connect(url)
}

function connect(url: string): void {
  wsState = reconnectAttempt > 0 ? "reconnecting" : "connecting"
  ws = new WebSocket(url)

  ws.onopen = () => {
    reconnectAttempt = 0
    paused = false
    wsState = "connected"
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

  ws.onclose = () => {
    ws = null
    wsState = "reconnecting"
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
    if (sendQueue.length >= OUTBOUND_BUFFER_SIZE) {
      sendQueue.shift()
    }
    sendQueue.push(json)
    return
  }

  if (paused) {
    if (sendQueue.length >= OUTBOUND_BUFFER_SIZE) {
      sendQueue.shift()
    }
    sendQueue.push(json)
    return
  }

  ws.send(json)

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
  const baseMs = Math.min(
    WS_RECONNECT_BASE_MS * Math.pow(2, reconnectAttempt),
    WS_RECONNECT_MAX_MS,
  )
  const jitterMs = Math.random() * WS_RECONNECT_JITTER_MS
  const delayMs = baseMs + jitterMs
  reconnectAttempt++

  // setTimeout for short reconnect delays. Destroyed if SW terminates,
  // but chrome.alarms has a 30-second minimum which is too slow for initial retries.
  setTimeout(() => connect(url), delayMs)
}

export function isConnected(): boolean {
  return ws !== null && ws.readyState === WebSocket.OPEN
}

export function getReconnectAttempt(): number {
  return reconnectAttempt
}

// Exposed for testing
export function _reset(): void {
  ws = null
  reconnectAttempt = 0
  callbacks = null
  paused = false
  sendQueue = []
  currentUrl = null
  wsState = "disconnected"
}
