import { describe, it, expect } from "vitest"
import type {
  TabConnect,
  TabDisconnect,
  TabNavigate,
  DomSnapshot,
  DomMutations,
  DomResponse,
  NetRequest,
  NetResponse,
  UserAction,
  UserChat,
  ActionResult,
  FlowPressure,
  ActionExecute,
  ActionCancel,
  DomRequestCmd,
  UiUpdate,
  FlowThrottle,
  FlowResume,
  ExtensionMessage,
  DaemonMessage,
  ActionStep,
} from "~/lib/types"

function roundTrip<T>(msg: T): T {
  return JSON.parse(JSON.stringify(msg))
}

describe("Extension to Daemon messages", () => {
  it("TabConnect preserves all fields", () => {
    const msg: TabConnect = {
      type: "tab.connect",
      tabId: 42,
      sessionId: "abc123",
      url: "https://example.com",
      title: "Example",
      domain: "example.com",
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("TabDisconnect preserves all fields", () => {
    const msg: TabDisconnect = { type: "tab.disconnect", tabId: 42 }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("TabNavigate preserves all fields", () => {
    const msg: TabNavigate = {
      type: "tab.navigate",
      tabId: 42,
      url: "https://example.com/page",
      title: "Page",
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("DomSnapshot preserves all fields", () => {
    const msg: DomSnapshot = {
      type: "dom.snapshot",
      tabId: 42,
      html: "<div>test</div>",
      selector: "body",
      timestamp: 1700000000000,
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("DomMutations preserves all fields including nested summaries", () => {
    const msg: DomMutations = {
      type: "dom.mutations",
      tabId: 42,
      mutations: [
        { type: "childList", target: "#app", addedCount: 3, removedCount: 1 },
        { type: "attributes", target: ".btn", attr: "class", value: "active" },
        { type: "characterData", target: "p.text" },
      ],
      timestamp: 1700000000000,
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("DomResponse preserves elements array", () => {
    const msg: DomResponse = {
      type: "dom.response",
      tabId: 42,
      requestId: "req-1",
      elements: [
        { "data-id": "abc", href: "/link" },
        { "data-id": "def", href: "/other" },
      ],
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("NetRequest preserves all fields", () => {
    const msg: NetRequest = {
      type: "net.request",
      tabId: 42,
      requestId: "req-1",
      method: "POST",
      url: "https://api.example.com/data",
      timestamp: 1700000000000,
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("NetResponse preserves all fields including optional bodyPreview", () => {
    const msg: NetResponse = {
      type: "net.response",
      tabId: 42,
      requestId: "req-1",
      url: "https://api.example.com/data",
      status: 200,
      contentType: "application/json",
      bodyPreview: '{"ok":true}',
      durationMs: 150,
      timestamp: 1700000000000,
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("NetResponse without bodyPreview omits the field", () => {
    const msg: NetResponse = {
      type: "net.response",
      tabId: 42,
      requestId: "req-1",
      url: "https://api.example.com/data",
      status: 404,
      contentType: "text/html",
      durationMs: 50,
      timestamp: 1700000000000,
    }
    const result = roundTrip(msg)
    expect(result).toEqual(msg)
    expect(result.bodyPreview).toBeUndefined()
  })

  it("UserAction preserves all fields including optional value", () => {
    const msg: UserAction = {
      type: "user.action",
      tabId: 42,
      action: "type",
      target: 'input[name="email"]',
      value: "user@test.com",
      timestamp: 1700000000000,
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("UserChat preserves all fields", () => {
    const msg: UserChat = {
      type: "user.chat",
      tabId: 42,
      text: "What is on this page?",
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("ActionResult preserves all fields including optional data and error", () => {
    const success: ActionResult = {
      type: "action.result",
      tabId: 42,
      actionId: "act-1",
      success: true,
      data: { textContent: "Hello" },
    }
    expect(roundTrip(success)).toEqual(success)

    const failure: ActionResult = {
      type: "action.result",
      tabId: 42,
      actionId: "act-2",
      success: false,
      error: "Element not found: #missing",
    }
    expect(roundTrip(failure)).toEqual(failure)
  })

  it("FlowPressure preserves all fields", () => {
    const msg: FlowPressure = {
      type: "flow.pressure",
      tabId: 42,
      bufferDepth: 48,
    }
    expect(roundTrip(msg)).toEqual(msg)
  })
})

describe("Daemon to Extension messages", () => {
  it("ActionExecute preserves steps array with all op variants", () => {
    const msg: ActionExecute = {
      type: "action.execute",
      tabId: 42,
      actionId: "act-1",
      steps: [
        { op: "waitForSelector", selector: "#login", timeoutMs: 5000 },
        { op: "click", selector: "#login" },
        { op: "type", selector: "#email", value: "test@example.com" },
        { op: "fill", selector: "#pass", value: "secret", mainWorld: true },
        { op: "click", selector: "#submit" },
        { op: "delay", ms: 500 },
      ],
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("ActionCancel preserves all fields", () => {
    const msg: ActionCancel = {
      type: "action.cancel",
      tabId: 42,
      actionId: "act-1",
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("DomRequestCmd preserves all fields including optional attrs and limit", () => {
    const msg: DomRequestCmd = {
      type: "dom.request",
      tabId: 42,
      requestId: "dreq-1",
      selector: "a.nav-link",
      attrs: ["href", "data-id"],
      limit: 10,
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("UiUpdate preserves all fields", () => {
    const msg: UiUpdate = {
      type: "ui.update",
      target: "conversation",
      data: { role: "agent", text: "I found three buttons." },
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("FlowThrottle preserves all fields", () => {
    const msg: FlowThrottle = {
      type: "flow.throttle",
      tabId: 42,
      maxEventsPerSec: 5,
    }
    expect(roundTrip(msg)).toEqual(msg)
  })

  it("FlowResume preserves all fields", () => {
    const msg: FlowResume = { type: "flow.resume", tabId: 42 }
    expect(roundTrip(msg)).toEqual(msg)
  })
})

describe("ActionStep variants", () => {
  const steps: ActionStep[] = [
    { op: "click", selector: "#btn" },
    { op: "clickAll", selector: ".item", delayMs: 100 },
    { op: "type", selector: "#input", value: "hello" },
    { op: "type", selector: "#input", value: "hello", mainWorld: true },
    { op: "fill", selector: "#input", value: "hello" },
    { op: "fill", selector: "#input", value: "hello", mainWorld: true },
    { op: "select", selector: "select#role", value: "admin" },
    { op: "check", selector: "#agree", checked: true },
    { op: "press", key: "Enter" },
    { op: "scroll", selector: "#content", x: 0, y: 500 },
    { op: "waitForSelector", selector: ".loaded", timeoutMs: 3000 },
    { op: "waitForRemoval", selector: ".spinner" },
    { op: "waitForText", selector: "#status", text: "Done", timeoutMs: 5000 },
    { op: "waitForNetwork", urlPattern: "/api/save" },
    { op: "getAttribute", selector: "#link", attr: "href" },
    { op: "getTextContent", selector: "#title" },
    { op: "evaluate", expression: "document.title" },
    { op: "evaluate", expression: "window.appState", mainWorld: true },
    { op: "delay", ms: 1000 },
  ]

  it("all 16 ops round-trip through JSON", () => {
    for (const step of steps) {
      expect(roundTrip(step)).toEqual(step)
    }
  })

  it("optional fields are omitted when not set", () => {
    const step: ActionStep = { op: "click", selector: "#btn" }
    const json = JSON.stringify(step)
    expect(json).not.toContain("delayMs")
    expect(json).not.toContain("mainWorld")
    expect(json).not.toContain("timeoutMs")
  })
})

describe("Union type discrimination", () => {
  it("ExtensionMessage discriminates by type field", () => {
    const messages: ExtensionMessage[] = [
      {
        type: "tab.connect",
        tabId: 1,
        sessionId: "s",
        url: "u",
        title: "t",
        domain: "d",
      },
      { type: "tab.disconnect", tabId: 1 },
      { type: "flow.pressure", tabId: 1, bufferDepth: 10 },
    ]

    for (const msg of messages) {
      const parsed = roundTrip(msg)
      expect(parsed.type).toBe(msg.type)
    }
  })

  it("DaemonMessage discriminates by type field", () => {
    const messages: DaemonMessage[] = [
      { type: "action.execute", tabId: 1, actionId: "a", steps: [] },
      { type: "action.cancel", tabId: 1, actionId: "a" },
      { type: "dom.request", tabId: 1, requestId: "r", selector: "s" },
      { type: "ui.update", target: "status", data: {} },
      { type: "flow.throttle", tabId: 1, maxEventsPerSec: 10 },
      { type: "flow.resume", tabId: 1 },
    ]

    for (const msg of messages) {
      const parsed = roundTrip(msg)
      expect(parsed.type).toBe(msg.type)
    }
  })
})
