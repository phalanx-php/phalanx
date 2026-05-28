// Wire protocol types -- single source of truth for the extension side.
// Mirrors integration/SPEC.md Section 1.

export interface BaseMessage {
  type: string
}

// -- Extension to Daemon --

export interface TabConnect extends BaseMessage {
  type: "tab.connect"
  tabId: number
  sessionId: string
  url: string
  title: string
  domain: string
}

export interface TabDisconnect extends BaseMessage {
  type: "tab.disconnect"
  tabId: number
}

export interface TabNavigate extends BaseMessage {
  type: "tab.navigate"
  tabId: number
  url: string
  title: string
}

export interface DomSnapshot extends BaseMessage {
  type: "dom.snapshot"
  tabId: number
  html: string
  selector: string
  timestamp: number
}

export interface DomMutations extends BaseMessage {
  type: "dom.mutations"
  tabId: number
  mutations: MutationSummary[]
  timestamp: number
}

export interface MutationSummary {
  type: "childList" | "attributes" | "characterData"
  target: string
  addedCount?: number
  removedCount?: number
  attr?: string
  value?: string
}

export interface DomResponse extends BaseMessage {
  type: "dom.response"
  tabId: number
  requestId: string
  elements: Record<string, string | null>[]
}

export interface NetRequest extends BaseMessage {
  type: "net.request"
  tabId: number
  requestId: string
  method: string
  url: string
  timestamp: number
}

export interface NetResponse extends BaseMessage {
  type: "net.response"
  tabId: number
  requestId: string
  url: string
  status: number
  contentType: string
  bodyPreview?: string
  durationMs: number
  timestamp: number
}

export interface UserAction extends BaseMessage {
  type: "user.action"
  tabId: number
  action: "click" | "type" | "select" | "scroll" | "submit"
  target: string
  value?: string
  timestamp: number
}

export interface UserChat extends BaseMessage {
  type: "user.chat"
  tabId: number
  text: string
}

export interface ActionResult extends BaseMessage {
  type: "action.result"
  tabId: number
  actionId: string
  success: boolean
  data?: Record<string, unknown>
  error?: string
}

export interface FlowPressure extends BaseMessage {
  type: "flow.pressure"
  tabId: number
  bufferDepth: number
}

export type ExtensionMessage =
  | TabConnect
  | TabDisconnect
  | TabNavigate
  | DomSnapshot
  | DomMutations
  | DomResponse
  | NetRequest
  | NetResponse
  | UserAction
  | UserChat
  | ActionResult
  | FlowPressure

// -- Daemon to Extension --

export interface ActionExecute extends BaseMessage {
  type: "action.execute"
  tabId: number
  actionId: string
  steps: ActionStep[]
}

export interface ActionCancel extends BaseMessage {
  type: "action.cancel"
  tabId: number
  actionId: string
}

export interface DomRequestCmd extends BaseMessage {
  type: "dom.request"
  tabId: number
  requestId: string
  selector: string
  attrs?: string[]
  limit?: number
}

export interface UiUpdate extends BaseMessage {
  type: "ui.update"
  target: "status" | "confidence" | "conversation"
  data: Record<string, unknown>
}

export interface FlowThrottle extends BaseMessage {
  type: "flow.throttle"
  tabId: number
  maxEventsPerSec: number
}

export interface FlowResume extends BaseMessage {
  type: "flow.resume"
  tabId: number
}

export type DaemonMessage =
  | ActionExecute
  | ActionCancel
  | DomRequestCmd
  | UiUpdate
  | FlowThrottle
  | FlowResume

// -- Action Steps --
// 16 ops, dispatched by `op` discriminator.
// `fill`, `type`, and `evaluate` support optional `mainWorld` for MAIN world execution.

export type ActionStep =
  | { op: "click"; selector: string }
  | { op: "clickAll"; selector: string; delayMs?: number }
  | { op: "type"; selector: string; value: string; mainWorld?: boolean }
  | { op: "fill"; selector: string; value: string; mainWorld?: boolean }
  | { op: "select"; selector: string; value: string }
  | { op: "check"; selector: string; checked: boolean }
  | { op: "press"; key: string }
  | { op: "scroll"; selector: string; x?: number; y?: number }
  | { op: "waitForSelector"; selector: string; timeoutMs?: number }
  | { op: "waitForRemoval"; selector: string; timeoutMs?: number }
  | { op: "waitForText"; selector: string; text: string; timeoutMs?: number }
  | { op: "waitForNetwork"; urlPattern: string; timeoutMs?: number }
  | { op: "getAttribute"; selector: string; attr: string }
  | { op: "getTextContent"; selector: string }
  | { op: "evaluate"; expression: string; mainWorld?: boolean }
  | { op: "delay"; ms: number }
