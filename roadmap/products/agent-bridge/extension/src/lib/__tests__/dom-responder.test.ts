import { describe, it, expect, vi, beforeEach } from "vitest"
import type { ExtensionMessage, DomRequestCmd } from "~/lib/types"

// -- Global stubs --

vi.stubGlobal("CSS", { escape: (v: string) => v })
vi.stubGlobal("Node", { ELEMENT_NODE: 1, TEXT_NODE: 3 })
class FakeHTMLElement {}
vi.stubGlobal("HTMLElement", FakeHTMLElement)

// -- DOM element factory --

function makeFakeElement(
  attributes: Record<string, string>,
): { getAttribute: (n: string) => string | null; getAttributeNames: () => string[] } {
  return {
    getAttribute: (name: string) => attributes[name] ?? null,
    getAttributeNames: () => Object.keys(attributes),
  }
}

type FakeEl = ReturnType<typeof makeFakeElement>
let registeredElements: FakeEl[]

const mockDocument = {
  querySelectorAll: vi.fn((_sel: string) => {
    // Create a proper array-like with indexed access (NodeList compat)
    const result: Record<string | number | symbol, unknown> = {
      length: registeredElements.length,
      [Symbol.iterator]: function* () {
        yield* registeredElements
      },
    }
    registeredElements.forEach((el, i) => {
      result[i] = el
    })
    return result
  }),
  querySelector: vi.fn(),
  addEventListener: vi.fn(),
  removeEventListener: vi.fn(),
  body: { innerHTML: "" },
}
vi.stubGlobal("document", mockDocument)

import { handleDomRequest } from "~/lib/dom-responder"

describe("dom-responder", () => {
  let sent: ExtensionMessage[]

  beforeEach(() => {
    sent = []
    registeredElements = []
    mockDocument.querySelectorAll.mockClear()
  })

  const send = (msg: ExtensionMessage) => sent.push(msg)
  const TAB_ID = 42

  it("returns requested attrs for matching elements", () => {
    registeredElements = [
      makeFakeElement({ id: "row-1", "data-name": "Alice", class: "row" }),
      makeFakeElement({ id: "row-2", "data-name": "Bob", class: "row" }),
    ]

    const req: DomRequestCmd = {
      type: "dom.request",
      tabId: TAB_ID,
      requestId: "dreq_1",
      selector: ".row",
      attrs: ["id", "data-name"],
    }

    handleDomRequest(req, TAB_ID, send)

    expect(sent).toHaveLength(1)
    const resp = sent[0] as { type: string; requestId: string; elements: Record<string, string | null>[] }
    expect(resp.type).toBe("dom.response")
    expect(resp.requestId).toBe("dreq_1")
    expect(resp.elements).toHaveLength(2)
    expect(resp.elements[0]).toEqual({ id: "row-1", "data-name": "Alice" })
    expect(resp.elements[1]).toEqual({ id: "row-2", "data-name": "Bob" })
  })

  it("returns data-* attributes when attrs is not specified", () => {
    registeredElements = [
      makeFakeElement({
        id: "card",
        "data-id": "123",
        "data-type": "post",
        class: "card",
      }),
    ]

    const req: DomRequestCmd = {
      type: "dom.request",
      tabId: TAB_ID,
      requestId: "dreq_2",
      selector: ".card",
    }

    handleDomRequest(req, TAB_ID, send)

    const resp = sent[0] as { elements: Record<string, string | null>[] }
    expect(resp.elements).toHaveLength(1)
    // Only data-* attributes should be returned
    expect(resp.elements[0]).toEqual({
      "data-id": "123",
      "data-type": "post",
    })
  })

  it("respects limit parameter", () => {
    registeredElements = [
      makeFakeElement({ id: "a" }),
      makeFakeElement({ id: "b" }),
      makeFakeElement({ id: "c" }),
    ]

    const req: DomRequestCmd = {
      type: "dom.request",
      tabId: TAB_ID,
      requestId: "dreq_3",
      selector: "div",
      attrs: ["id"],
      limit: 2,
    }

    handleDomRequest(req, TAB_ID, send)

    const resp = sent[0] as { elements: Record<string, string | null>[] }
    expect(resp.elements).toHaveLength(2)
  })

  it("returns empty array when selector matches nothing", () => {
    registeredElements = []

    const req: DomRequestCmd = {
      type: "dom.request",
      tabId: TAB_ID,
      requestId: "dreq_4",
      selector: ".nonexistent",
      attrs: ["id"],
    }

    handleDomRequest(req, TAB_ID, send)

    const resp = sent[0] as { elements: Record<string, string | null>[] }
    expect(resp.elements).toHaveLength(0)
  })

  it("returns null for missing attributes", () => {
    registeredElements = [makeFakeElement({ id: "item" })]

    const req: DomRequestCmd = {
      type: "dom.request",
      tabId: TAB_ID,
      requestId: "dreq_5",
      selector: "#item",
      attrs: ["id", "data-missing"],
    }

    handleDomRequest(req, TAB_ID, send)

    const resp = sent[0] as { elements: Record<string, string | null>[] }
    expect(resp.elements[0]).toEqual({
      id: "item",
      "data-missing": null,
    })
  })
})
