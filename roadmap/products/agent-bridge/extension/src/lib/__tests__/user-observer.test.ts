import { describe, it, expect, vi, beforeEach, afterEach } from "vitest"
import type { ExtensionMessage } from "~/lib/types"

// Mock CSS.escape
vi.stubGlobal("CSS", { escape: (v: string) => v })

// Stub Node constants
vi.stubGlobal("Node", { ELEMENT_NODE: 1, TEXT_NODE: 3 })

// Stub HTMLElement for isInvisible checks
class FakeHTMLElement {}
vi.stubGlobal("HTMLElement", FakeHTMLElement)

// Stub HTMLInputElement, HTMLTextAreaElement, HTMLSelectElement, HTMLFormElement
class FakeHTMLInputElement { value = "" }
class FakeHTMLTextAreaElement { value = "" }
class FakeHTMLSelectElement { value = "" }
class FakeHTMLFormElement {}

vi.stubGlobal("HTMLInputElement", FakeHTMLInputElement)
vi.stubGlobal("HTMLTextAreaElement", FakeHTMLTextAreaElement)
vi.stubGlobal("HTMLSelectElement", FakeHTMLSelectElement)
vi.stubGlobal("HTMLFormElement", FakeHTMLFormElement)

// Track registered listeners so we can fire events manually
type ListenerEntry = {
  type: string
  handler: EventListener
  options?: AddEventListenerOptions
}

let listeners: ListenerEntry[] = []
let abortSignals: AbortSignal[] = []

const mockDocument = {
  addEventListener: vi.fn(
    (
      type: string,
      handler: EventListener,
      options?: AddEventListenerOptions,
    ) => {
      if (options?.signal) {
        abortSignals.push(options.signal)
        options.signal.addEventListener("abort", () => {
          listeners = listeners.filter((l) => l.handler !== handler)
        })
      }
      listeners.push({ type, handler, options })
    },
  ),
  removeEventListener: vi.fn(),
}

vi.stubGlobal("document", mockDocument)

// Mock AbortController
class MockAbortController {
  signal = {
    aborted: false,
    _listeners: [] as (() => void)[],
    addEventListener(_: string, fn: () => void) {
      this._listeners.push(fn)
    },
  }
  abort() {
    this.signal.aborted = true
    this.signal._listeners.forEach((fn) => fn())
  }
}
vi.stubGlobal("AbortController", MockAbortController)

function fireEvent(type: string, target: unknown): void {
  const event = { type, target } as unknown as Event
  for (const entry of listeners) {
    if (entry.type === type) {
      entry.handler(event)
    }
  }
}

function makeInputElement(
  testId: string,
  value: string,
): FakeHTMLInputElement & { getAttribute: (n: string) => string | null; tagName: string; id: string; classList: { [Symbol.iterator]: () => Iterator<string>; length: number }; parentElement: null } {
  const el = Object.create(FakeHTMLInputElement.prototype)
  el.value = value
  el.tagName = "INPUT"
  el.id = ""
  el.classList = { [Symbol.iterator]: function* () {}, length: 0 }
  el.parentElement = null
  el.getAttribute = (name: string) =>
    name === "data-testid" ? testId : null
  return el
}

function makeSelectElement(
  testId: string,
  value: string,
): FakeHTMLSelectElement & { getAttribute: (n: string) => string | null; tagName: string; id: string; classList: { [Symbol.iterator]: () => Iterator<string>; length: number }; parentElement: null } {
  const el = Object.create(FakeHTMLSelectElement.prototype)
  el.value = value
  el.tagName = "SELECT"
  el.id = ""
  el.classList = { [Symbol.iterator]: function* () {}, length: 0 }
  el.parentElement = null
  el.getAttribute = (name: string) =>
    name === "data-testid" ? testId : null
  return el
}

function makeFormElement(
  testId: string,
): FakeHTMLFormElement & { getAttribute: (n: string) => string | null; tagName: string; id: string; classList: { [Symbol.iterator]: () => Iterator<string>; length: number }; parentElement: null } {
  const el = Object.create(FakeHTMLFormElement.prototype)
  el.tagName = "FORM"
  el.id = ""
  el.classList = { [Symbol.iterator]: function* () {}, length: 0 }
  el.parentElement = null
  el.getAttribute = (name: string) =>
    name === "data-testid" ? testId : null
  return el
}

function makeClickTarget(testId: string) {
  return {
    tagName: "BUTTON",
    id: "",
    classList: { [Symbol.iterator]: function* () {}, length: 0 },
    parentElement: null,
    getAttribute: (name: string) =>
      name === "data-testid" ? testId : null,
  }
}

import {
  startUserActionListeners,
  setActionInProgress,
} from "~/lib/user-observer"

describe("user-observer", () => {
  let sent: ExtensionMessage[]
  let cleanup: () => void

  beforeEach(() => {
    vi.useFakeTimers()
    sent = []
    listeners = []
    abortSignals = []
    mockDocument.addEventListener.mockClear()
    setActionInProgress(false)
    cleanup = startUserActionListeners((msg) => sent.push(msg), 42)
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  it("sends click events with correct shape", () => {
    const target = makeClickTarget("submit-btn")
    fireEvent("click", target)

    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      type: "user.action",
      tabId: 42,
      action: "click",
      target: '[data-testid="submit-btn"]',
    })
    expect((sent[0] as { timestamp: number }).timestamp).toBeTypeOf("number")
  })

  it("sends debounced type events for input elements", () => {
    const input = makeInputElement("email-field", "a")
    fireEvent("input", input)

    // Not sent immediately
    expect(sent).toHaveLength(0)

    // Update value and trigger again within debounce window
    input.value = "ab"
    fireEvent("input", input)

    // Still nothing -- debounce resets
    expect(sent).toHaveLength(0)

    // Advance past debounce timeout
    vi.advanceTimersByTime(300)

    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      type: "user.action",
      tabId: 42,
      action: "type",
      target: '[data-testid="email-field"]',
      value: "ab",
    })
  })

  it("sends select events for select elements", () => {
    const select = makeSelectElement("country", "US")
    fireEvent("change", select)

    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      type: "user.action",
      tabId: 42,
      action: "select",
      target: '[data-testid="country"]',
      value: "US",
    })
  })

  it("sends scroll events throttled to 500ms", () => {
    fireEvent("scroll", null)
    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      type: "user.action",
      action: "scroll",
      target: "document",
    })

    // Second scroll within 500ms should be throttled
    fireEvent("scroll", null)
    expect(sent).toHaveLength(1)

    // After throttle window, trailing edge fires
    vi.advanceTimersByTime(500)
    expect(sent).toHaveLength(2)
  })

  it("sends submit events for form elements", () => {
    const form = makeFormElement("login-form")
    fireEvent("submit", form)

    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      type: "user.action",
      tabId: 42,
      action: "submit",
      target: '[data-testid="login-form"]',
    })
  })

  it("suppresses all events when actionInProgress is true", () => {
    setActionInProgress(true)

    fireEvent("click", makeClickTarget("btn"))
    fireEvent("input", makeInputElement("field", "val"))
    vi.advanceTimersByTime(300)
    fireEvent("change", makeSelectElement("sel", "opt"))
    fireEvent("scroll", null)
    fireEvent("submit", makeFormElement("form"))

    expect(sent).toHaveLength(0)
  })

  it("resumes events when actionInProgress is cleared", () => {
    setActionInProgress(true)
    fireEvent("click", makeClickTarget("btn"))
    expect(sent).toHaveLength(0)

    setActionInProgress(false)
    fireEvent("click", makeClickTarget("btn"))
    expect(sent).toHaveLength(1)
  })

  it("ignores click events with null target", () => {
    fireEvent("click", null)
    expect(sent).toHaveLength(0)
  })

  it("ignores change events on non-select elements", () => {
    fireEvent("change", makeClickTarget("checkbox"))
    expect(sent).toHaveLength(0)
  })

  it("ignores input events on non-input/textarea elements", () => {
    // A div with contenteditable fires input events but isn't an input/textarea
    fireEvent("input", makeClickTarget("editable-div"))
    vi.advanceTimersByTime(300)
    expect(sent).toHaveLength(0)
  })

  it("cleanup stops all listeners", () => {
    cleanup()
    fireEvent("click", makeClickTarget("btn"))
    expect(sent).toHaveLength(0)
  })
})
