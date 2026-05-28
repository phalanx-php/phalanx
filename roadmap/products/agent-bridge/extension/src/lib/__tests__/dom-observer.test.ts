import { describe, it, expect, vi, beforeEach } from "vitest"
import type { ExtensionMessage, MutationSummary } from "~/lib/types"

// Mock CSS.escape -- not available in Node
vi.stubGlobal("CSS", { escape: (v: string) => v })

// -- Minimal DOM mocks for generateSelector and summarizeMutations --

function makeElement(opts: {
  tag?: string
  id?: string
  testId?: string
  ariaLabel?: string
  classes?: string[]
  connected?: boolean
  visible?: boolean
  parent?: { children: Element[] }
  attributes?: Record<string, string>
} = {}): Element {
  const tag = opts.tag ?? "div"
  const attrs: Record<string, string | null> = {}
  if (opts.id) attrs.id = opts.id
  if (opts.testId) attrs["data-testid"] = opts.testId
  if (opts.ariaLabel) attrs["aria-label"] = opts.ariaLabel
  if (opts.attributes) Object.assign(attrs, opts.attributes)

  const classList = opts.classes ?? []

  const el = {
    tagName: tag.toUpperCase(),
    id: opts.id ?? "",
    nodeType: 1, // Node.ELEMENT_NODE
    isConnected: opts.connected ?? true,
    offsetParent: opts.visible !== false ? {} : null,
    offsetWidth: opts.visible !== false ? 100 : 0,
    offsetHeight: opts.visible !== false ? 50 : 0,
    classList: {
      [Symbol.iterator]: function* () { yield* classList },
      length: classList.length,
    },
    parentElement: opts.parent
      ? {
          children: opts.parent.children,
        }
      : null,
    getAttribute: (name: string) => attrs[name] ?? null,
  } as unknown as Element

  // Patch parent's children to include this element if provided
  if (opts.parent) {
    opts.parent.children.push(el)
  }

  return el
}

function makeMutationRecord(opts: {
  type: MutationRecordType
  target: Node
  attributeName?: string
  addedNodes?: Node[]
  removedNodes?: Node[]
}): MutationRecord {
  return {
    type: opts.type,
    target: opts.target,
    attributeName: opts.attributeName ?? null,
    oldValue: null,
    addedNodes: (opts.addedNodes ?? []) as unknown as NodeList,
    removedNodes: (opts.removedNodes ?? []) as unknown as NodeList,
    previousSibling: null,
    nextSibling: null,
  } as unknown as MutationRecord
}

// Stub getComputedStyle for invisible element checks
vi.stubGlobal("getComputedStyle", () => ({
  display: "none",
  position: "static",
}))

// Stub Node constants
vi.stubGlobal("Node", {
  ELEMENT_NODE: 1,
  TEXT_NODE: 3,
})

// Stub HTMLElement for instanceof checks in isInvisible
class FakeHTMLElement {}
vi.stubGlobal("HTMLElement", FakeHTMLElement)

import { generateSelector, summarizeMutations } from "~/lib/dom-observer"

describe("generateSelector", () => {
  it("prefers data-testid when present", () => {
    const el = makeElement({ testId: "login-button" })
    expect(generateSelector(el)).toBe('[data-testid="login-button"]')
  })

  it("uses stable id when no data-testid", () => {
    const el = makeElement({ id: "main-nav" })
    expect(generateSelector(el)).toBe("#main-nav")
  })

  it("rejects auto-generated hex ids", () => {
    const el = makeElement({ id: "a3f1b2c4d5e6", ariaLabel: "menu" })
    expect(generateSelector(el)).toBe('[aria-label="menu"]')
  })

  it("rejects UUID-style ids", () => {
    const el = makeElement({
      id: "a3f1b2c4-d5e6-4fff-8aaa-123456789abc",
      ariaLabel: "sidebar",
    })
    expect(generateSelector(el)).toBe('[aria-label="sidebar"]')
  })

  it("rejects React-style :r...: ids", () => {
    const el = makeElement({ id: ":r0:", classes: ["header"] })
    expect(generateSelector(el)).toBe("div.header")
  })

  it("uses aria-label when no stable id", () => {
    const el = makeElement({ ariaLabel: "Close dialog" })
    expect(generateSelector(el)).toBe('[aria-label="Close dialog"]')
  })

  it("uses tag.className with stable classes", () => {
    const el = makeElement({ tag: "button", classes: ["btn", "btn-primary"] })
    expect(generateSelector(el)).toBe("button.btn.btn-primary")
  })

  it("filters out generated class names", () => {
    // CSS module hash pattern: short prefix + hex
    const el = makeElement({
      tag: "span",
      classes: ["sc1a2b3c", "title"],
    })
    expect(generateSelector(el)).toBe("span.title")
  })

  it("falls back to nth-child when no identifiers", () => {
    const parentChildren: Element[] = []
    // Add a sibling first
    makeElement({ tag: "div", parent: { children: parentChildren } })
    const el = makeElement({ tag: "div", parent: { children: parentChildren } })
    expect(generateSelector(el)).toBe("div:nth-child(2)")
  })

  it("falls back to bare tag name when no parent", () => {
    const el = makeElement({ tag: "body" })
    expect(generateSelector(el)).toBe("body")
  })

  it("prefers data-testid over id", () => {
    const el = makeElement({ id: "stable-id", testId: "test-anchor" })
    expect(generateSelector(el)).toBe('[data-testid="test-anchor"]')
  })
})

describe("summarizeMutations", () => {
  it("summarizes childList additions", () => {
    const target = makeElement({ testId: "list" }) as unknown as Node
    const addedNode = makeElement({ tag: "li" }) as unknown as Node
    ;(addedNode as unknown as { forEach: unknown }).forEach = undefined

    const record = makeMutationRecord({
      type: "childList",
      target,
      addedNodes: [addedNode],
    })

    const summaries = summarizeMutations([record])
    expect(summaries).toHaveLength(1)
    expect(summaries[0]).toEqual({
      type: "childList",
      target: '[data-testid="list"]',
      addedCount: 1,
      removedCount: 0,
    })
  })

  it("summarizes childList removals", () => {
    const target = makeElement({ testId: "list" }) as unknown as Node
    const removed1 = makeElement({ tag: "li" }) as unknown as Node
    const removed2 = makeElement({ tag: "li" }) as unknown as Node

    const record = makeMutationRecord({
      type: "childList",
      target,
      removedNodes: [removed1, removed2],
    })

    const summaries = summarizeMutations([record])
    expect(summaries).toHaveLength(1)
    expect(summaries[0].removedCount).toBe(2)
  })

  it("summarizes text node additions", () => {
    const target = makeElement({ testId: "content" }) as unknown as Node
    const textNode = { nodeType: 3 } as unknown as Node // TEXT_NODE

    const record = makeMutationRecord({
      type: "childList",
      target,
      addedNodes: [textNode],
    })

    const summaries = summarizeMutations([record])
    expect(summaries).toHaveLength(1)
    expect(summaries[0].addedCount).toBe(1)
  })

  it("summarizes attribute changes", () => {
    const target = makeElement({
      testId: "input",
      attributes: { class: "active" },
    }) as unknown as Node

    const record = makeMutationRecord({
      type: "attributes",
      target,
      attributeName: "class",
    })

    const summaries = summarizeMutations([record])
    expect(summaries).toHaveLength(1)
    expect(summaries[0]).toEqual({
      type: "attributes",
      target: '[data-testid="input"]',
      attr: "class",
      value: "active",
    })
  })

  it("deduplicates attribute changes on the same element", () => {
    const el = makeElement({
      testId: "btn",
      attributes: { class: "final-value" },
    })
    const target = el as unknown as Node

    const record1 = makeMutationRecord({
      type: "attributes",
      target,
      attributeName: "class",
    })
    const record2 = makeMutationRecord({
      type: "attributes",
      target,
      attributeName: "class",
    })

    const summaries = summarizeMutations([record1, record2])
    // Should merge into a single summary with the latest value
    expect(summaries).toHaveLength(1)
    expect(summaries[0].attr).toBe("class")
    expect(summaries[0].value).toBe("final-value")
  })

  it("keeps separate entries for different attributes on same element", () => {
    const el = makeElement({
      testId: "el",
      attributes: { class: "active", disabled: "true" },
    })
    const target = el as unknown as Node

    const record1 = makeMutationRecord({
      type: "attributes",
      target,
      attributeName: "class",
    })
    const record2 = makeMutationRecord({
      type: "attributes",
      target,
      attributeName: "disabled",
    })

    const summaries = summarizeMutations([record1, record2])
    expect(summaries).toHaveLength(2)
    expect(summaries[0].attr).toBe("class")
    expect(summaries[1].attr).toBe("disabled")
  })

  it("summarizes characterData changes", () => {
    const textNode = {
      nodeType: 3, // TEXT_NODE
      textContent: "updated text",
      parentElement: makeElement({ testId: "paragraph" }),
    } as unknown as Node

    const record = makeMutationRecord({
      type: "characterData",
      target: textNode,
    })

    const summaries = summarizeMutations([record])
    expect(summaries).toHaveLength(1)
    expect(summaries[0]).toEqual({
      type: "characterData",
      target: '[data-testid="paragraph"]',
      value: "updated text",
    })
  })

  it("filters disconnected elements", () => {
    const target = makeElement({
      testId: "gone",
      connected: false,
    }) as unknown as Node

    const record = makeMutationRecord({
      type: "attributes",
      target,
      attributeName: "class",
    })

    const summaries = summarizeMutations([record])
    expect(summaries).toHaveLength(0)
  })

  it("filters invisible elements (display: none)", () => {
    // Make the element an instanceof HTMLElement so isInvisible checks it
    const el = Object.assign(Object.create(FakeHTMLElement.prototype), {
      tagName: "DIV",
      id: "",
      nodeType: 1,
      isConnected: true,
      offsetParent: null, // display:none
      offsetWidth: 0,
      offsetHeight: 0,
      classList: { [Symbol.iterator]: function* () {}, length: 0 },
      parentElement: null,
      getAttribute: () => null,
    }) as unknown as Element

    const target = el as unknown as Node

    const record = makeMutationRecord({
      type: "attributes",
      target,
      attributeName: "class",
    })

    const summaries = summarizeMutations([record])
    expect(summaries).toHaveLength(0)
  })

  it("handles empty mutation records", () => {
    const summaries = summarizeMutations([])
    expect(summaries).toHaveLength(0)
  })

  it("skips childList with zero visible additions/removals", () => {
    const target = makeElement({ testId: "container" }) as unknown as Node

    const record = makeMutationRecord({
      type: "childList",
      target,
      addedNodes: [],
      removedNodes: [],
    })

    const summaries = summarizeMutations([record])
    expect(summaries).toHaveLength(0)
  })
})
