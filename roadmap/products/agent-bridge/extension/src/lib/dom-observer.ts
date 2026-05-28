// DOM observation layer -- MutationObserver with rAF batching and mutation summarization.
// Runs in the content script's ISOLATED world (shares DOM, not JS globals).

import type { ExtensionMessage, MutationSummary } from "./types"

// CSS selector generator with stability-first priority cascade.
// data-testid > stable id > aria-label > tag.className > tag:nth-child(n)
export function generateSelector(el: Element): string {
  // data-testid is the most stable anchor, preferred when present
  const testId = el.getAttribute("data-testid")
  if (testId) return `[data-testid="${testId}"]`

  // id -- only if it doesn't look auto-generated (hex hashes, UUIDs, numeric-heavy)
  if (el.id && isStableId(el.id)) return `#${CSS.escape(el.id)}`

  // aria-label for accessible elements
  const ariaLabel = el.getAttribute("aria-label")
  if (ariaLabel) return `[aria-label="${CSS.escape(ariaLabel)}"]`

  // tag.className -- filter out generated class names
  const tag = el.tagName.toLowerCase()
  const stableClasses = getStableClasses(el)
  if (stableClasses.length > 0) {
    return `${tag}.${stableClasses.map(CSS.escape).join(".")}`
  }

  // Last resort: tag:nth-child(n) for positional identification
  const parent = el.parentElement
  if (parent) {
    const siblings = Array.from(parent.children)
    const index = siblings.indexOf(el) + 1
    return `${tag}:nth-child(${index})`
  }

  return tag
}

// Auto-generated IDs contain hex hashes, UUIDs, or are mostly digits.
// These change across page loads, so they're useless as stable selectors.
const UNSTABLE_ID_PATTERN =
  /^[0-9a-f]{6,}$|^[0-9a-f]{8}-[0-9a-f]{4}-|^\d+$|^:r[0-9a-z]+:$/i

function isStableId(id: string): boolean {
  return !UNSTABLE_ID_PATTERN.test(id)
}

// Generated class names: short hex strings (CSS modules hashes), utility-first
// atomic classes that are just numbers, or anything that looks like a build hash.
const UNSTABLE_CLASS_PATTERN = /^[a-z]{1,2}[0-9a-f]{4,}$|^[0-9a-f]{6,}$/i

function getStableClasses(el: Element): string[] {
  return Array.from(el.classList).filter(
    (cls) => cls.length > 0 && !UNSTABLE_CLASS_PATTERN.test(cls),
  )
}

// Determines if an element is visually invisible (display: none, zero dimensions).
// Invisible elements are noise -- mutations on them don't affect what the user sees.
function isInvisible(el: Element): boolean {
  if (!(el instanceof HTMLElement)) return false

  // offsetParent is null for display:none and position:fixed elements.
  // position:fixed elements ARE visible, so check for that case.
  if (el.offsetParent === null) {
    const style = getComputedStyle(el)
    if (style.display === "none") return true
    // position:fixed with zero dimensions is still invisible
    if (style.position === "fixed") {
      return el.offsetWidth === 0 && el.offsetHeight === 0
    }
    // Not fixed and no offsetParent -- likely display:none ancestor
    return true
  }

  return el.offsetWidth === 0 && el.offsetHeight === 0
}

// Convert raw MutationRecords into wire-format MutationSummary[].
// Deduplicates attribute changes on the same element within a single rAF frame.
export function summarizeMutations(records: MutationRecord[]): MutationSummary[] {
  const summaries: MutationSummary[] = []

  // Track attribute changes per element to merge multiple changes in one frame.
  // Key: element reference, Value: map of attr name to summary index
  const attrMap = new Map<Node, Map<string, number>>()

  for (const record of records) {
    const target = record.target
    const el =
      target.nodeType === Node.ELEMENT_NODE
        ? (target as Element)
        : target.parentElement

    // Skip mutations on disconnected or invisible elements
    if (!el || !el.isConnected || isInvisible(el)) continue

    const selector = generateSelector(el)

    if (record.type === "childList") {
      // Count only visible added/removed nodes
      let addedCount = 0
      let removedCount = 0

      record.addedNodes.forEach((node) => {
        if (node.nodeType === Node.ELEMENT_NODE) {
          if (!isInvisible(node as Element)) addedCount++
        } else if (node.nodeType === Node.TEXT_NODE) {
          addedCount++
        }
      })

      record.removedNodes.forEach((node) => {
        if (node.nodeType === Node.ELEMENT_NODE) {
          // Removed nodes are detached -- can't check visibility, always count
          removedCount++
        } else if (node.nodeType === Node.TEXT_NODE) {
          removedCount++
        }
      })

      if (addedCount > 0 || removedCount > 0) {
        summaries.push({
          type: "childList",
          target: selector,
          addedCount,
          removedCount,
        })
      }
    } else if (record.type === "attributes") {
      const attr = record.attributeName ?? ""

      // Deduplicate: if we already recorded an attribute change for this
      // element+attr combo in this frame, update the value in place
      let elementAttrs = attrMap.get(target)
      if (!elementAttrs) {
        elementAttrs = new Map()
        attrMap.set(target, elementAttrs)
      }

      const existingIndex = elementAttrs.get(attr)
      const currentValue = el.getAttribute(attr)

      if (existingIndex !== undefined) {
        // Update existing summary with the latest value
        summaries[existingIndex].value = currentValue ?? undefined
      } else {
        const index = summaries.length
        elementAttrs.set(attr, index)
        summaries.push({
          type: "attributes",
          target: selector,
          attr,
          value: currentValue ?? undefined,
        })
      }
    } else if (record.type === "characterData") {
      summaries.push({
        type: "characterData",
        target: selector,
        value: target.textContent ?? undefined,
      })
    }
  }

  return summaries
}

// Capture the current DOM as an initial snapshot for the daemon.
// Called once after __init handshake and again on __resend_snapshot.
export function sendInitialSnapshot(
  send: (msg: ExtensionMessage) => void,
  tabId: number,
): void {
  send({
    type: "dom.snapshot",
    tabId,
    html: document.body?.innerHTML ?? "",
    selector: "body",
    timestamp: Date.now(),
  })
}

// Start observing DOM mutations with rAF-batched delivery.
// Returns a cleanup function that stops observation and cancels pending frames.
export function startObserving(
  send: (msg: ExtensionMessage) => void,
  tabId: number,
): () => void {
  let pendingRecords: MutationRecord[] = []
  let rafId: number | null = null

  const observer = new MutationObserver((records) => {
    pendingRecords.push(...records)

    // Batch all mutations that arrive within a single animation frame.
    // rAF fires once per frame (~16ms at 60fps), which naturally coalesces
    // rapid-fire mutations from DOM-heavy pages into a single wire message.
    if (rafId === null) {
      rafId = requestAnimationFrame(() => {
        rafId = null
        const batch = pendingRecords
        pendingRecords = []

        const summaries = summarizeMutations(batch)
        if (summaries.length === 0) return

        send({
          type: "dom.mutations",
          tabId,
          mutations: summaries,
          timestamp: Date.now(),
        })
      })
    }
  })

  // Observe everything -- attributeFilter doesn't support wildcards in MV3,
  // so we observe all attributes and filter in summarizeMutations instead.
  observer.observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    characterData: true,
  })

  return () => {
    observer.disconnect()
    if (rafId !== null) {
      cancelAnimationFrame(rafId)
      rafId = null
    }
    pendingRecords = []
  }
}
