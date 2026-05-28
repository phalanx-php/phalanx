import { describe, it, expect, vi, beforeEach, afterEach } from "vitest"
import type { ExtensionMessage, ActionStep } from "~/lib/types"

// -- Global stubs matching the project's manual mock pattern --

vi.stubGlobal("CSS", { escape: (v: string) => v })
vi.stubGlobal("Node", { ELEMENT_NODE: 1, TEXT_NODE: 3 })

class FakeHTMLElement {}
vi.stubGlobal("HTMLElement", FakeHTMLElement)

// Stub KeyboardEvent constructor
class FakeKeyboardEvent {
  type: string
  key: string
  bubbles: boolean
  constructor(type: string, init?: { key?: string; bubbles?: boolean }) {
    this.type = type
    this.key = init?.key ?? ""
    this.bubbles = init?.bubbles ?? false
  }
}
vi.stubGlobal("KeyboardEvent", FakeKeyboardEvent)

// Stub Event constructor
class FakeEvent {
  type: string
  bubbles: boolean
  constructor(type: string, init?: { bubbles?: boolean }) {
    this.type = type
    this.bubbles = init?.bubbles ?? false
  }
}
vi.stubGlobal("Event", FakeEvent)

// -- DOM element factories --

function makeFakeElement(
  overrides: Partial<{
    tagName: string
    id: string
    textContent: string
    value: string
    checked: boolean
    getAttribute: (name: string) => string | null
    classList: { [Symbol.iterator]: () => Iterator<string>; length: number }
    parentElement: unknown
  }> = {},
) {
  const el = Object.create(FakeHTMLElement.prototype) as Record<string, unknown>
  el.tagName = overrides.tagName ?? "DIV"
  el.id = overrides.id ?? ""
  el.textContent = overrides.textContent ?? ""
  el.value = overrides.value ?? ""
  el.checked = overrides.checked ?? false
  el.classList = overrides.classList ?? {
    [Symbol.iterator]: function* () {},
    length: 0,
  }
  el.parentElement = overrides.parentElement ?? null
  el.getAttribute =
    overrides.getAttribute ?? ((name: string) => (name === "id" && el.id ? el.id as string : null))
  el.click = vi.fn()
  el.focus = vi.fn()
  el.scrollIntoView = vi.fn()
  el.scrollTo = vi.fn()
  el.dispatchEvent = vi.fn()
  el.getAttributeNames = vi.fn(() => [])
  return el
}

// Track registered elements so querySelector/querySelectorAll can find them
let registeredElements: Map<string, ReturnType<typeof makeFakeElement>[]>

function registerElement(selector: string, el: ReturnType<typeof makeFakeElement>): void {
  const existing = registeredElements.get(selector) ?? []
  existing.push(el)
  registeredElements.set(selector, existing)
}

// Mock document
const mockDocument = {
  querySelector: vi.fn((sel: string) => {
    const els = registeredElements.get(sel)
    return els?.[0] ?? null
  }),
  querySelectorAll: vi.fn((sel: string) => {
    const els = registeredElements.get(sel) ?? []
    return {
      length: els.length,
      [Symbol.iterator]: function* () {
        yield* els
      },
      ...els,
    }
  }),
  dispatchEvent: vi.fn(),
  addEventListener: vi.fn(),
  removeEventListener: vi.fn(),
  body: { innerHTML: "" },
}
vi.stubGlobal("document", mockDocument)

// requestAnimationFrame backed by setTimeout so vitest fake timers can control it.
// In Node.js, rAF doesn't exist natively; this bridges the gap.
let rafCounter = 0
vi.stubGlobal("requestAnimationFrame", (cb: FrameRequestCallback) => {
  const id = ++rafCounter
  setTimeout(() => cb(performance.now()), 16)
  return id
})

// Mock port for evaluate/waitForNetwork tests
function makeMockPort() {
  const listeners: ((msg: Record<string, unknown>) => void)[] = []
  return {
    postMessage: vi.fn(),
    onMessage: {
      addListener: vi.fn((fn: (msg: Record<string, unknown>) => void) => {
        listeners.push(fn)
      }),
      removeListener: vi.fn((fn: (msg: Record<string, unknown>) => void) => {
        const idx = listeners.indexOf(fn)
        if (idx >= 0) listeners.splice(idx, 1)
      }),
    },
    // Helper to simulate receiving a message from SW
    _receive(msg: Record<string, unknown>) {
      // Copy to avoid mutation during iteration
      ;[...listeners].forEach((fn) => fn(msg))
    },
    _listeners: listeners,
  }
}

// -- Import after all stubs --

import {
  executeAction,
  cancelledActions,
  waitFor,
} from "~/lib/action-executor"
import { setActionInProgress, getActionInProgress } from "~/lib/user-observer"

describe("action-executor", () => {
  let sent: ExtensionMessage[]
  let port: ReturnType<typeof makeMockPort>

  beforeEach(() => {
    vi.useFakeTimers()
    sent = []
    registeredElements = new Map()
    cancelledActions.clear()
    setActionInProgress(false)
    port = makeMockPort()
    mockDocument.querySelector.mockClear()
    mockDocument.querySelectorAll.mockClear()
    mockDocument.dispatchEvent.mockClear()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  const send = (msg: ExtensionMessage) => sent.push(msg)
  const TAB_ID = 42

  // -- click --

  it("click: calls element.click() and scrollIntoView", async () => {
    const el = makeFakeElement()
    registerElement("#btn", el)

    await executeAction(
      [{ op: "click", selector: "#btn" }],
      "act_1",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(el.scrollIntoView).toHaveBeenCalledWith({
      behavior: "smooth",
      block: "center",
    })
    expect(el.click).toHaveBeenCalled()
    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      type: "action.result",
      actionId: "act_1",
      success: true,
    })
  })

  it("click: fails when element not found", async () => {
    await executeAction(
      [{ op: "click", selector: "#missing" }],
      "act_2",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      type: "action.result",
      actionId: "act_2",
      success: false,
      error: "Element not found: #missing",
    })
  })

  // -- type --

  it("type: sets value character by character with key events", async () => {
    const el = makeFakeElement({ value: "" })
    registerElement("#input", el)

    await executeAction(
      [{ op: "type", selector: "#input", value: "ab" }],
      "act_3",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(el.focus).toHaveBeenCalled()
    // Initial clear + per-char input events + final change
    // 1 (clear input) + 2*4 (keydown,keypress,input,keyup per char) + 1 (change) = 10
    // But we dispatch: clear input, then per char: keydown, keypress, set value, input, keyup
    // So dispatchEvent calls: 1(clear) + 2*(keydown+keypress+input+keyup) + 1(change) = 10
    expect(el.dispatchEvent).toHaveBeenCalled()
    expect(el.value).toBe("ab")
    expect(sent[0]).toMatchObject({ success: true })
  })

  // -- fill --

  it("fill: sets value directly with input and change events", async () => {
    const el = makeFakeElement({ value: "" })
    registerElement("#field", el)

    await executeAction(
      [{ op: "fill", selector: "#field", value: "hello" }],
      "act_4",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(el.focus).toHaveBeenCalled()
    expect(el.value).toBe("hello")
    // input + change events
    expect(el.dispatchEvent).toHaveBeenCalledTimes(2)
    expect(sent[0]).toMatchObject({ success: true })
  })

  // -- select --

  it("select: sets value and dispatches change", async () => {
    const el = makeFakeElement({ tagName: "SELECT", value: "a" })
    registerElement("#sel", el)

    await executeAction(
      [{ op: "select", selector: "#sel", value: "b" }],
      "act_5",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(el.value).toBe("b")
    expect(el.dispatchEvent).toHaveBeenCalledTimes(1)
    expect(sent[0]).toMatchObject({ success: true })
  })

  // -- check --

  it("check: sets checked property and dispatches change", async () => {
    const el = makeFakeElement({ checked: false })
    registerElement("#chk", el)

    await executeAction(
      [{ op: "check", selector: "#chk", checked: true }],
      "act_6",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(el.checked).toBe(true)
    expect(el.dispatchEvent).toHaveBeenCalledTimes(1)
    expect(sent[0]).toMatchObject({ success: true })
  })

  // -- press --

  it("press: dispatches keydown/keypress/keyup on document", async () => {
    await executeAction(
      [{ op: "press", key: "Enter" }],
      "act_7",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(mockDocument.dispatchEvent).toHaveBeenCalledTimes(3)
    expect(sent[0]).toMatchObject({ success: true })
  })

  // -- scroll --

  it("scroll: calls scrollIntoView when no coordinates", async () => {
    const el = makeFakeElement()
    registerElement("#scr", el)

    await executeAction(
      [{ op: "scroll", selector: "#scr" }],
      "act_8",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(el.scrollIntoView).toHaveBeenCalledWith({
      behavior: "smooth",
      block: "center",
    })
  })

  it("scroll: calls scrollTo when coordinates provided", async () => {
    const el = makeFakeElement()
    registerElement("#scr", el)

    await executeAction(
      [{ op: "scroll", selector: "#scr", x: 0, y: 200 }],
      "act_9",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(el.scrollTo).toHaveBeenCalledWith(0, 200)
  })

  // -- waitForSelector --

  it("waitForSelector: resolves when element appears", async () => {
    const promise = executeAction(
      [{ op: "waitForSelector", selector: "#appears", timeoutMs: 1000 }],
      "act_10",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    // First poll -- not found
    await vi.advanceTimersByTimeAsync(16)
    expect(sent).toHaveLength(0)

    // Register the element so next poll finds it
    registerElement("#appears", makeFakeElement())
    await vi.advanceTimersByTimeAsync(16)

    await promise
    expect(sent[0]).toMatchObject({ success: true })
  })

  it("waitForSelector: times out when element never appears", async () => {
    const promise = executeAction(
      [{ op: "waitForSelector", selector: "#never", timeoutMs: 100 }],
      "act_11",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    // Advance past timeout -- rAF checks Date.now() against deadline
    await vi.advanceTimersByTimeAsync(150)

    await promise
    expect(sent[0]).toMatchObject({
      success: false,
      error: "Timeout after 100ms",
    })
  })

  // -- waitForRemoval --

  it("waitForRemoval: resolves when element disappears", async () => {
    registerElement("#gone", makeFakeElement())

    const promise = executeAction(
      [{ op: "waitForRemoval", selector: "#gone", timeoutMs: 1000 }],
      "act_12",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    // First poll -- still there
    await vi.advanceTimersByTimeAsync(16)
    expect(sent).toHaveLength(0)

    // Remove the element
    registeredElements.delete("#gone")
    await vi.advanceTimersByTimeAsync(16)

    await promise
    expect(sent[0]).toMatchObject({ success: true })
  })

  // -- waitForText --

  it("waitForText: resolves when text matches", async () => {
    const el = makeFakeElement({ textContent: "Loading..." })
    registerElement("#status", el)

    const promise = executeAction(
      [{ op: "waitForText", selector: "#status", text: "Done", timeoutMs: 1000 }],
      "act_13",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    await vi.advanceTimersByTimeAsync(16)
    expect(sent).toHaveLength(0)

    el.textContent = "Done!"
    await vi.advanceTimersByTimeAsync(16)

    await promise
    expect(sent[0]).toMatchObject({ success: true })
  })

  // -- getAttribute --

  it("getAttribute: returns attribute value", async () => {
    const el = makeFakeElement({
      getAttribute: (name: string) => (name === "href" ? "/path" : null),
    })
    registerElement("#link", el)

    await executeAction(
      [{ op: "getAttribute", selector: "#link", attr: "href" }],
      "act_14",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(sent[0]).toMatchObject({
      success: true,
      data: { value: "/path" },
    })
  })

  it("getAttribute: fails when element not found", async () => {
    await executeAction(
      [{ op: "getAttribute", selector: "#nope", attr: "href" }],
      "act_15",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(sent[0]).toMatchObject({
      success: false,
      error: "Element not found: #nope",
    })
  })

  // -- getTextContent --

  it("getTextContent: returns text content", async () => {
    const el = makeFakeElement({ textContent: "Hello World" })
    registerElement("#text", el)

    await executeAction(
      [{ op: "getTextContent", selector: "#text" }],
      "act_16",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(sent[0]).toMatchObject({
      success: true,
      data: { value: "Hello World" },
    })
  })

  // -- delay --

  it("delay: waits specified duration", async () => {
    const promise = executeAction(
      [{ op: "delay", ms: 500 }],
      "act_17",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(sent).toHaveLength(0)
    vi.advanceTimersByTime(500)
    await promise
    expect(sent[0]).toMatchObject({ success: true })
  })

  // -- Sequential execution --

  it("executeAction: halts on first failure", async () => {
    const el = makeFakeElement()
    registerElement("#exists", el)

    await executeAction(
      [
        { op: "click", selector: "#exists" },
        { op: "click", selector: "#missing" },
        { op: "click", selector: "#exists" },
      ],
      "act_18",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    // First click succeeds, second fails, third never runs
    expect(el.click).toHaveBeenCalledTimes(1)
    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      success: false,
      error: "Element not found: #missing",
    })
  })

  // -- Cancellation --

  it("executeAction: respects cancellation between steps", async () => {
    const el = makeFakeElement()
    registerElement("#btn", el)

    // Pre-cancel before execution starts
    cancelledActions.add("act_19")

    await executeAction(
      [
        { op: "click", selector: "#btn" },
        { op: "click", selector: "#btn" },
      ],
      "act_19",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(el.click).not.toHaveBeenCalled()
    expect(sent[0]).toMatchObject({
      success: false,
      error: "cancelled",
    })
    // Cleanup: actionId removed from set
    expect(cancelledActions.has("act_19")).toBe(false)
  })

  // -- actionInProgress flag --

  it("sets actionInProgress during execution and clears in finally", async () => {
    const el = makeFakeElement()
    registerElement("#btn", el)

    expect(getActionInProgress()).toBe(false)

    const promise = executeAction(
      [{ op: "delay", ms: 100 }],
      "act_20",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    // During execution, flag should be true
    expect(getActionInProgress()).toBe(true)

    vi.advanceTimersByTime(100)
    await promise

    // After completion, flag should be cleared
    expect(getActionInProgress()).toBe(false)
  })

  it("clears actionInProgress even on failure", async () => {
    await executeAction(
      [{ op: "click", selector: "#missing" }],
      "act_21",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(getActionInProgress()).toBe(false)
  })

  // -- clickAll --

  it("clickAll: clicks all matching elements", async () => {
    const el1 = makeFakeElement()
    const el2 = makeFakeElement()
    registerElement(".item", el1)
    registerElement(".item", el2)

    await executeAction(
      [{ op: "clickAll", selector: ".item" }],
      "act_22",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(el1.click).toHaveBeenCalled()
    expect(el2.click).toHaveBeenCalled()
    expect(sent[0]).toMatchObject({ success: true })
  })

  it("clickAll: delays between clicks when delayMs set", async () => {
    const el1 = makeFakeElement()
    const el2 = makeFakeElement()
    registerElement(".item", el1)
    registerElement(".item", el2)

    const promise = executeAction(
      [{ op: "clickAll", selector: ".item", delayMs: 100 }],
      "act_23",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    // First click happens immediately
    expect(el1.click).toHaveBeenCalled()
    expect(el2.click).not.toHaveBeenCalled()

    vi.advanceTimersByTime(100)
    await promise

    expect(el2.click).toHaveBeenCalled()
  })

  // -- evaluate (port messaging) --

  it("evaluate: sends __evaluate and resolves on __evaluateResult", async () => {
    const promise = executeAction(
      [{ op: "evaluate", expression: "document.title" }],
      "act_24",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    // Port should have received the __evaluate message
    expect(port.postMessage).toHaveBeenCalledWith({
      type: "__evaluate",
      expression: "document.title",
    })

    // Simulate SW response
    port._receive({
      type: "__evaluateResult",
      result: { ok: true, value: "Test Page" },
    })

    await promise
    expect(sent[0]).toMatchObject({
      success: true,
      data: { value: "Test Page" },
    })
  })

  it("evaluate: rejects on error response", async () => {
    const promise = executeAction(
      [{ op: "evaluate", expression: "badCode()" }],
      "act_25",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    port._receive({
      type: "__evaluateResult",
      result: { ok: false, error: "badCode is not defined" },
    })

    await promise
    expect(sent[0]).toMatchObject({
      success: false,
      error: "badCode is not defined",
    })
  })

  it("evaluate: times out", async () => {
    const promise = executeAction(
      [{ op: "evaluate", expression: "slowOp()" }],
      "act_26",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    // Advance past SW_EVALUATE_TIMEOUT_MS (10000)
    await vi.advanceTimersByTimeAsync(10_001)

    await promise
    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({ success: false })
    const error = (sent[0] as { error?: string }).error ?? ""
    expect(error.includes("timed out")).toBe(true)
  })

  // -- waitForNetwork (port messaging) --

  it("waitForNetwork: sends __waitForNetwork and resolves on __networkComplete", async () => {
    const promise = executeAction(
      [{ op: "waitForNetwork", urlPattern: "**/api/data" }],
      "act_27",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(port.postMessage).toHaveBeenCalledWith({
      type: "__waitForNetwork",
      urlPattern: "**/api/data",
      timeoutMs: 10_000,
    })

    port._receive({
      type: "__networkComplete",
      url: "https://example.com/api/data",
    })

    await promise
    expect(sent[0]).toMatchObject({ success: true })
  })

  it("waitForNetwork: rejects on timeout response", async () => {
    const promise = executeAction(
      [{ op: "waitForNetwork", urlPattern: "**/slow", timeoutMs: 500 }],
      "act_28",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    // Simulate timeout from the content-script side timer
    await vi.advanceTimersByTimeAsync(501)

    await promise
    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({ success: false })
    const error = (sent[0] as { error?: string }).error ?? ""
    expect(error.includes("timed out")).toBe(true)
  })

  // -- waitFor utility --

  describe("waitFor", () => {
    it("resolves immediately when check passes", async () => {
      const promise = waitFor(() => true, 1000)
      await vi.advanceTimersByTimeAsync(16)
      await promise
    })

    it("rejects after timeout", async () => {
      const promise = waitFor(() => false, 100)
      // Attach a no-op catch to prevent unhandled rejection during timer advancement
      promise.catch(() => {})

      await vi.advanceTimersByTimeAsync(150)

      await expect(promise).rejects.toThrow("Timeout after 100ms")
    })
  })

  // -- Multi-step with reads --

  it("returns only the last read result in data", async () => {
    const el1 = makeFakeElement({ textContent: "first" })
    const el2 = makeFakeElement({ textContent: "second" })
    registerElement("#a", el1)
    registerElement("#b", el2)

    await executeAction(
      [
        { op: "getTextContent", selector: "#a" },
        { op: "getTextContent", selector: "#b" },
      ],
      "act_29",
      TAB_ID,
      send,
      port as unknown as chrome.runtime.Port,
    )

    expect(sent[0]).toMatchObject({
      success: true,
      data: { value: "second" },
    })
  })
})
