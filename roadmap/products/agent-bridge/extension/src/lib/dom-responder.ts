// DOM responder -- handles daemon dom.request commands by querying the live DOM
// and returning structured attribute data. Runs in the content script's ISOLATED world.

import type { DomRequestCmd, ExtensionMessage } from "./types"

export function handleDomRequest(
  msg: DomRequestCmd,
  tabId: number,
  send: (msg: ExtensionMessage) => void,
): void {
  const allElements = document.querySelectorAll(msg.selector)
  const limit = msg.limit ?? allElements.length
  const elements: Record<string, string | null>[] = []

  for (let i = 0; i < Math.min(allElements.length, limit); i++) {
    const el = allElements[i]
    const attrs: Record<string, string | null> = {}

    if (msg.attrs) {
      // Extract only the requested attributes
      for (const attr of msg.attrs) {
        attrs[attr] = el.getAttribute(attr)
      }
    } else {
      // No attrs specified -- extract all data-* attributes
      for (const attr of el.getAttributeNames()) {
        if (attr.startsWith("data-")) {
          attrs[attr] = el.getAttribute(attr)
        }
      }
    }

    elements.push(attrs)
  }

  send({
    type: "dom.response",
    tabId,
    requestId: msg.requestId,
    elements,
  })
}
