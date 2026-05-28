// User action observation layer -- captures real user interactions and sends
// them to the daemon for policy learning. Suppressed when the agent is
// executing actions to avoid contaminating the policy model.

import type { ExtensionMessage } from "./types"
import { generateSelector } from "./dom-observer"

// Phase 3: action executor not yet built. This flag is always false.
// Phase 4 will import the real flag from the action executor module.
// The guard is wired now so the suppression path is exercised from day one.
let _actionInProgress = false

export function setActionInProgress(value: boolean): void {
  _actionInProgress = value
}

export function getActionInProgress(): boolean {
  return _actionInProgress
}

export function startUserActionListeners(
  send: (msg: ExtensionMessage) => void,
  tabId: number,
): () => void {
  const abortController = new AbortController()
  const signal = abortController.signal

  // Click -- capture phase so we see it before any stopPropagation
  document.addEventListener(
    "click",
    (e) => {
      if (_actionInProgress) return
      const target = e.target as Element | null
      if (!target) return

      send({
        type: "user.action",
        tabId,
        action: "click",
        target: generateSelector(target),
        timestamp: Date.now(),
      })
    },
    { capture: true, signal },
  )

  // Input -- debounced so we don't flood the wire with every keystroke.
  // 300ms after the user stops typing, send one message with the current value.
  const inputTimers = new WeakMap<EventTarget, ReturnType<typeof setTimeout>>()

  document.addEventListener(
    "input",
    (e) => {
      if (_actionInProgress) return
      const target = e.target
      if (!target) return
      if (
        !(target instanceof HTMLInputElement) &&
        !(target instanceof HTMLTextAreaElement)
      )
        return

      const existing = inputTimers.get(target)
      if (existing !== undefined) clearTimeout(existing)

      const el = target as HTMLInputElement | HTMLTextAreaElement
      inputTimers.set(
        target,
        setTimeout(() => {
          inputTimers.delete(target)
          send({
            type: "user.action",
            tabId,
            action: "type",
            target: generateSelector(el),
            value: el.value,
            timestamp: Date.now(),
          })
        }, 300),
      )
    },
    { capture: true, signal },
  )

  // Change -- for select elements (and checkbox/radio, but click already covers those)
  document.addEventListener(
    "change",
    (e) => {
      if (_actionInProgress) return
      const target = e.target
      if (!(target instanceof HTMLSelectElement)) return

      send({
        type: "user.action",
        tabId,
        action: "select",
        target: generateSelector(target),
        value: target.value,
        timestamp: Date.now(),
      })
    },
    { capture: true, signal },
  )

  // Scroll -- throttled to max 1 per 500ms to avoid flooding
  let lastScrollTime = 0
  let scrollTimer: ReturnType<typeof setTimeout> | null = null

  document.addEventListener(
    "scroll",
    () => {
      if (_actionInProgress) return

      const now = Date.now()
      if (now - lastScrollTime < 500) {
        // Within throttle window -- schedule trailing edge
        if (scrollTimer === null) {
          scrollTimer = setTimeout(() => {
            scrollTimer = null
            lastScrollTime = Date.now()
            send({
              type: "user.action",
              tabId,
              action: "scroll",
              target: "document",
              timestamp: Date.now(),
            })
          }, 500 - (now - lastScrollTime))
        }
        return
      }

      lastScrollTime = now
      send({
        type: "user.action",
        tabId,
        action: "scroll",
        target: "document",
        timestamp: Date.now(),
      })
    },
    { capture: true, passive: true, signal },
  )

  // Submit -- on form elements
  document.addEventListener(
    "submit",
    (e) => {
      if (_actionInProgress) return
      const target = e.target
      if (!(target instanceof HTMLFormElement)) return

      send({
        type: "user.action",
        tabId,
        action: "submit",
        target: generateSelector(target),
        timestamp: Date.now(),
      })
    },
    { capture: true, signal },
  )

  // Cleanup: abort all listeners and clear pending timers
  return () => {
    abortController.abort()
    if (scrollTimer !== null) {
      clearTimeout(scrollTimer)
      scrollTimer = null
    }
    // Input timers are weakly referenced -- they'll be GC'd with their elements
  }
}
