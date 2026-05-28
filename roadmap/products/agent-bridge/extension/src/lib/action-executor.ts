// Action execution layer -- sequential step executor for daemon action.execute commands.
// Runs in the content script's ISOLATED world. Steps that need MAIN world access
// (evaluate, mainWorld fill/type) route through the service worker via port messages.

import type { ActionStep, ExtensionMessage } from "./types"
import { ACTION_TIMEOUT_MS, SW_EVALUATE_TIMEOUT_MS, SW_NETWORK_WAIT_TIMEOUT_MS } from "./constants"
import { setActionInProgress } from "./user-observer"

// Exported for bridge.ts to check cancellation state
export const cancelledActions = new Set<string>()

// Last read result from getAttribute/getTextContent/evaluate -- returned in action.result
let lastReadResult: Record<string, unknown> | undefined

export async function executeAction(
  steps: ActionStep[],
  actionId: string,
  tabId: number,
  send: (msg: ExtensionMessage) => void,
  port: chrome.runtime.Port,
): Promise<void> {
  setActionInProgress(true)
  lastReadResult = undefined

  try {
    for (const step of steps) {
      // Check cancellation before each step
      if (cancelledActions.has(actionId)) {
        send({
          type: "action.result",
          tabId,
          actionId,
          success: false,
          error: "cancelled",
        })
        return
      }

      try {
        const result = await executeStep(step, port)

        // Capture read results for the final action.result
        if (result !== undefined) {
          lastReadResult = { value: result }
        }
      } catch (err: unknown) {
        const message = err instanceof Error ? err.message : String(err)
        send({
          type: "action.result",
          tabId,
          actionId,
          success: false,
          error: message,
        })
        return
      }
    }

    // All steps succeeded
    send({
      type: "action.result",
      tabId,
      actionId,
      success: true,
      data: lastReadResult,
    })
  } finally {
    cancelledActions.delete(actionId)
    setActionInProgress(false)
  }
}

async function executeStep(
  step: ActionStep,
  port: chrome.runtime.Port,
): Promise<unknown> {
  switch (step.op) {
    case "click":
      return execClick(step.selector)

    case "clickAll":
      return execClickAll(step.selector, step.delayMs)

    case "type":
      return execType(step.selector, step.value)

    case "fill":
      return execFill(step.selector, step.value)

    case "select":
      return execSelect(step.selector, step.value)

    case "check":
      return execCheck(step.selector, step.checked)

    case "press":
      return execPress(step.key)

    case "scroll":
      return execScroll(step.selector, step.x, step.y)

    case "waitForSelector":
      return waitFor(
        () => document.querySelector(step.selector) !== null,
        step.timeoutMs ?? 5000,
      )

    case "waitForRemoval":
      return waitFor(
        () => document.querySelector(step.selector) === null,
        step.timeoutMs ?? 5000,
      )

    case "waitForText":
      return waitFor(
        () => {
          const el = document.querySelector(step.selector)
          return el !== null && (el.textContent ?? "").includes(step.text)
        },
        step.timeoutMs ?? 5000,
      )

    case "waitForNetwork":
      return execWaitForNetwork(port, step.urlPattern, step.timeoutMs)

    case "getAttribute":
      return execGetAttribute(step.selector, step.attr)

    case "getTextContent":
      return execGetTextContent(step.selector)

    case "evaluate":
      return execEvaluate(port, step.expression)

    case "delay":
      return new Promise<void>((resolve) => setTimeout(resolve, step.ms))

    default: {
      // Exhaustive check -- TypeScript will error if a new op is added without a case
      const _exhaustive: never = step
      throw new Error(`Unknown op: ${(_exhaustive as { op: string }).op}`)
    }
  }
}

// -- Individual Op Implementations --

function requireElement(selector: string): Element {
  const el = document.querySelector(selector)
  if (!el) throw new Error(`Element not found: ${selector}`)
  return el
}

function execClick(selector: string): void {
  const el = requireElement(selector) as HTMLElement
  el.scrollIntoView({ behavior: "smooth", block: "center" })
  el.click()
}

async function execClickAll(
  selector: string,
  delayMs?: number,
): Promise<void> {
  const els = document.querySelectorAll(selector)
  for (let i = 0; i < els.length; i++) {
    const el = els[i] as HTMLElement
    el.scrollIntoView({ behavior: "smooth", block: "center" })
    el.click()
    if (delayMs && i < els.length - 1) {
      await new Promise<void>((r) => setTimeout(r, delayMs))
    }
  }
}

function execType(selector: string, value: string): void {
  const el = requireElement(selector) as HTMLInputElement
  el.focus()
  el.value = ""
  el.dispatchEvent(new Event("input", { bubbles: true }))

  for (const char of value) {
    el.dispatchEvent(
      new KeyboardEvent("keydown", { key: char, bubbles: true }),
    )
    el.dispatchEvent(
      new KeyboardEvent("keypress", { key: char, bubbles: true }),
    )
    el.value += char
    el.dispatchEvent(new Event("input", { bubbles: true }))
    el.dispatchEvent(
      new KeyboardEvent("keyup", { key: char, bubbles: true }),
    )
  }

  el.dispatchEvent(new Event("change", { bubbles: true }))
}

function execFill(selector: string, value: string): void {
  const el = requireElement(selector) as HTMLInputElement
  el.focus()
  el.value = value
  el.dispatchEvent(new Event("input", { bubbles: true }))
  el.dispatchEvent(new Event("change", { bubbles: true }))
}

function execSelect(selector: string, value: string): void {
  const el = requireElement(selector) as HTMLSelectElement
  el.value = value
  el.dispatchEvent(new Event("change", { bubbles: true }))
}

function execCheck(selector: string, checked: boolean): void {
  const el = requireElement(selector) as HTMLInputElement
  el.checked = checked
  el.dispatchEvent(new Event("change", { bubbles: true }))
}

function execPress(key: string): void {
  const opts: KeyboardEventInit = { key, bubbles: true }
  document.dispatchEvent(new KeyboardEvent("keydown", opts))
  document.dispatchEvent(new KeyboardEvent("keypress", opts))
  document.dispatchEvent(new KeyboardEvent("keyup", opts))
}

function execScroll(
  selector: string,
  x?: number,
  y?: number,
): void {
  const el = requireElement(selector) as HTMLElement
  if (x !== undefined || y !== undefined) {
    el.scrollTo(x ?? 0, y ?? 0)
  } else {
    el.scrollIntoView({ behavior: "smooth", block: "center" })
  }
}

function execGetAttribute(selector: string, attr: string): string | null {
  const el = requireElement(selector)
  return el.getAttribute(attr)
}

function execGetTextContent(selector: string): string | null {
  const el = requireElement(selector)
  return el.textContent
}

// Routes through the service worker for MAIN world execution.
// Content scripts can't run code in the page's JS context directly.
function execEvaluate(
  port: chrome.runtime.Port,
  expression: string,
): Promise<unknown> {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      cleanup()
      reject(new Error(`evaluate timed out after ${SW_EVALUATE_TIMEOUT_MS}ms`))
    }, SW_EVALUATE_TIMEOUT_MS)

    function handler(msg: { type: string; result?: { ok: boolean; value?: unknown; error?: string } }) {
      if (msg.type !== "__evaluateResult") return
      cleanup()
      if (msg.result?.ok) {
        resolve(msg.result.value)
      } else {
        reject(new Error(msg.result?.error ?? "evaluate failed"))
      }
    }

    function cleanup() {
      clearTimeout(timeout)
      port.onMessage.removeListener(handler)
    }

    port.onMessage.addListener(handler)
    port.postMessage({ type: "__evaluate", expression })
  })
}

// Routes through the service worker because content scripts can't access chrome.webRequest.
function execWaitForNetwork(
  port: chrome.runtime.Port,
  urlPattern: string,
  timeoutMs?: number,
): Promise<void> {
  const timeout = timeoutMs ?? SW_NETWORK_WAIT_TIMEOUT_MS

  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      cleanup()
      reject(new Error(`waitForNetwork timed out after ${timeout}ms`))
    }, timeout)

    function handler(msg: { type: string; url?: string; error?: string }) {
      if (msg.type !== "__networkComplete") return
      cleanup()
      if (msg.error) {
        reject(new Error(msg.error))
      } else {
        resolve()
      }
    }

    function cleanup() {
      clearTimeout(timer)
      port.onMessage.removeListener(handler)
    }

    port.onMessage.addListener(handler)
    port.postMessage({ type: "__waitForNetwork", urlPattern, timeoutMs: timeout })
  })
}

// rAF-based polling with timeout. Used by waitForSelector, waitForRemoval, waitForText.
export function waitFor(
  check: () => boolean,
  timeoutMs: number,
): Promise<void> {
  return new Promise((resolve, reject) => {
    const deadline = Date.now() + timeoutMs

    function poll() {
      if (check()) return resolve()
      if (Date.now() > deadline)
        return reject(new Error(`Timeout after ${timeoutMs}ms`))
      requestAnimationFrame(poll)
    }

    requestAnimationFrame(poll)
  })
}
