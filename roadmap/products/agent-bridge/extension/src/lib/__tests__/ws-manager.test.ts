import { describe, it, expect, vi, beforeEach, afterEach } from "vitest"

// Mock WebSocket before importing ws-manager
const mockInstances: MockWebSocket[] = []

class MockWebSocket {
  static CONNECTING = 0 as const
  static OPEN = 1 as const
  static CLOSING = 2 as const
  static CLOSED = 3 as const

  CONNECTING = 0 as const
  OPEN = 1 as const
  CLOSING = 2 as const
  CLOSED = 3 as const

  url: string
  readyState: number = MockWebSocket.CONNECTING
  bufferedAmount: number = 0
  onopen: ((ev: Event) => void) | null = null
  onclose: ((ev: CloseEvent) => void) | null = null
  onmessage: ((ev: MessageEvent) => void) | null = null
  onerror: ((ev: Event) => void) | null = null
  sent: string[] = []

  constructor(url: string) {
    this.url = url
    mockInstances.push(this)
  }

  send(data: string): void {
    this.sent.push(data)
  }

  close(): void {
    this.readyState = MockWebSocket.CLOSED
  }

  simulateOpen(): void {
    this.readyState = MockWebSocket.OPEN
    this.onopen?.(new Event("open"))
  }

  simulateClose(): void {
    this.readyState = MockWebSocket.CLOSED
    this.onclose?.({ type: "close", wasClean: true, code: 1000, reason: "" } as CloseEvent)
  }

  simulateMessage(data: string): void {
    this.onmessage?.(new MessageEvent("message", { data }))
  }
}

vi.stubGlobal("WebSocket", MockWebSocket)

import {
  initWebSocket,
  sendToDaemon,
  isConnected,
  getWsState,
  getReconnectAttempt,
  _reset,
} from "~/lib/ws-manager"

describe("ws-manager", () => {
  beforeEach(() => {
    vi.useFakeTimers()
    _reset()
    mockInstances.length = 0
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it("connects to the given URL", () => {
    const onMessage = vi.fn()
    const onClose = vi.fn()
    const onOpen = vi.fn()

    initWebSocket("ws://localhost:9078/bridge", { onMessage, onClose, onOpen })

    expect(mockInstances).toHaveLength(1)
    expect(mockInstances[0].url).toBe("ws://localhost:9078/bridge")
    expect(getWsState()).toBe("connecting")
  })

  it("transitions to connected on open", () => {
    const onOpen = vi.fn()
    initWebSocket("ws://localhost:9078/bridge", {
      onMessage: vi.fn(),
      onClose: vi.fn(),
      onOpen,
    })

    mockInstances[0].simulateOpen()

    expect(getWsState()).toBe("connected")
    expect(isConnected()).toBe(true)
    expect(onOpen).toHaveBeenCalledOnce()
  })

  it("calls onMessage with parsed JSON", () => {
    const onMessage = vi.fn()
    initWebSocket("ws://localhost:9078/bridge", {
      onMessage,
      onClose: vi.fn(),
      onOpen: vi.fn(),
    })

    mockInstances[0].simulateOpen()
    mockInstances[0].simulateMessage(
      JSON.stringify({ type: "ui.update", target: "status", data: { state: "connected" } }),
    )

    expect(onMessage).toHaveBeenCalledOnce()
    expect(onMessage).toHaveBeenCalledWith({
      type: "ui.update",
      target: "status",
      data: { state: "connected" },
    })
  })

  it("silently drops malformed messages", () => {
    const onMessage = vi.fn()
    initWebSocket("ws://localhost:9078/bridge", {
      onMessage,
      onClose: vi.fn(),
      onOpen: vi.fn(),
    })

    mockInstances[0].simulateOpen()
    mockInstances[0].simulateMessage("not valid json {{{")

    expect(onMessage).not.toHaveBeenCalled()
  })

  it("sends messages when connected", () => {
    initWebSocket("ws://localhost:9078/bridge", {
      onMessage: vi.fn(),
      onClose: vi.fn(),
      onOpen: vi.fn(),
    })

    mockInstances[0].simulateOpen()
    sendToDaemon({ type: "tab.disconnect", tabId: 42 })

    expect(mockInstances[0].sent).toHaveLength(1)
    expect(JSON.parse(mockInstances[0].sent[0])).toEqual({
      type: "tab.disconnect",
      tabId: 42,
    })
  })

  it("queues messages when not connected", () => {
    initWebSocket("ws://localhost:9078/bridge", {
      onMessage: vi.fn(),
      onClose: vi.fn(),
      onOpen: vi.fn(),
    })

    // Still connecting -- not open yet
    sendToDaemon({ type: "tab.disconnect", tabId: 1 })
    sendToDaemon({ type: "tab.disconnect", tabId: 2 })

    expect(mockInstances[0].sent).toHaveLength(0)

    // Now open -- queued messages should flush
    mockInstances[0].simulateOpen()

    expect(mockInstances[0].sent).toHaveLength(2)
  })

  it("schedules reconnect with exponential backoff on close", () => {
    const onClose = vi.fn()
    initWebSocket("ws://localhost:9078/bridge", {
      onMessage: vi.fn(),
      onClose,
      onOpen: vi.fn(),
    })

    mockInstances[0].simulateOpen()
    mockInstances[0].simulateClose()

    expect(onClose).toHaveBeenCalledOnce()
    expect(getWsState()).toBe("reconnecting")
    expect(getReconnectAttempt()).toBe(1)

    // After ~2s (1s base + up to 1s jitter), a new WebSocket should be created
    vi.advanceTimersByTime(2100)
    expect(mockInstances).toHaveLength(2)
  })

  it("increases backoff on successive failures", () => {
    initWebSocket("ws://localhost:9078/bridge", {
      onMessage: vi.fn(),
      onClose: vi.fn(),
      onOpen: vi.fn(),
    })

    // First connection fails
    mockInstances[0].simulateOpen()
    mockInstances[0].simulateClose()
    expect(getReconnectAttempt()).toBe(1)

    // Advance past first reconnect (1s + jitter)
    vi.advanceTimersByTime(2100)
    expect(mockInstances).toHaveLength(2)

    // Second connection fails
    mockInstances[1].simulateClose()
    expect(getReconnectAttempt()).toBe(2)

    // Second reconnect should take ~2s base (2^1 * 1000) + jitter
    vi.advanceTimersByTime(1500)
    expect(mockInstances).toHaveLength(2) // Not yet
    vi.advanceTimersByTime(2000)
    expect(mockInstances).toHaveLength(3)
  })

  it("resets reconnect attempt counter on successful open", () => {
    initWebSocket("ws://localhost:9078/bridge", {
      onMessage: vi.fn(),
      onClose: vi.fn(),
      onOpen: vi.fn(),
    })

    mockInstances[0].simulateOpen()
    mockInstances[0].simulateClose()

    vi.advanceTimersByTime(2100)
    mockInstances[1].simulateOpen()

    expect(getReconnectAttempt()).toBe(0)
    expect(getWsState()).toBe("connected")
  })

  it("transitions to reconnecting state on close", () => {
    initWebSocket("ws://localhost:9078/bridge", {
      onMessage: vi.fn(),
      onClose: vi.fn(),
      onOpen: vi.fn(),
    })

    mockInstances[0].simulateOpen()
    expect(getWsState()).toBe("connected")

    mockInstances[0].simulateClose()
    expect(getWsState()).toBe("reconnecting")
  })
})
