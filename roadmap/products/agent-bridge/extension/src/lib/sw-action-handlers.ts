// Service worker handlers for content script internal messages.
// These handle operations that content scripts can't perform themselves:
// - __evaluate: runs JS in the page's MAIN world via chrome.scripting.executeScript
// - __waitForNetwork: monitors chrome.webRequest for matching URL patterns

import { SW_EVALUATE_TIMEOUT_MS, SW_NETWORK_WAIT_TIMEOUT_MS } from "./constants"

export async function handleEvaluate(
  msg: { type: string; expression: string },
  tabId: number,
  port: chrome.runtime.Port,
): Promise<void> {
  try {
    const results = await chrome.scripting.executeScript({
      target: { tabId },
      world: "MAIN",
      func: (expr: string) => {
        try {
          return { ok: true, value: new Function("return " + expr)() }
        } catch (e: unknown) {
          const error = e instanceof Error ? e : new Error(String(e))
          return { ok: false, error: error.message, stack: error.stack }
        }
      },
      args: [msg.expression],
    })

    const result = results?.[0]?.result ?? { ok: false, error: "No result returned" }
    port.postMessage({ type: "__evaluateResult", result })
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : String(err)
    port.postMessage({
      type: "__evaluateResult",
      result: { ok: false, error: message },
    })
  }
}

export function handleWaitForNetwork(
  msg: { type: string; urlPattern: string; timeoutMs: number },
  tabId: number,
  port: chrome.runtime.Port,
): void {
  const timeoutMs = msg.timeoutMs ?? SW_NETWORK_WAIT_TIMEOUT_MS

  // Convert glob pattern to regex for matching
  const regex = globToRegex(msg.urlPattern)

  const timeout = setTimeout(() => {
    cleanup()
    port.postMessage({ type: "__networkComplete", error: "timeout" })
  }, timeoutMs)

  function onCompleted(details: chrome.webRequest.WebResponseCacheDetails): void {
    if (details.tabId !== tabId) return
    if (!regex.test(details.url)) return

    cleanup()
    port.postMessage({ type: "__networkComplete", url: details.url })
  }

  function cleanup(): void {
    clearTimeout(timeout)
    chrome.webRequest.onCompleted.removeListener(onCompleted)
  }

  chrome.webRequest.onCompleted.addListener(onCompleted, {
    urls: ["<all_urls>"],
  })
}

// Convert a simple glob pattern (with * and **) to a RegExp.
// * matches any characters except /
// ** matches any characters including /
function globToRegex(pattern: string): RegExp {
  const escaped = pattern
    .replace(/[.+^${}()|[\]\\]/g, "\\$&")
    .replace(/\*\*/g, "@@DOUBLE@@")
    .replace(/\*/g, "[^/]*")
    .replace(/@@DOUBLE@@/g, ".*")
  return new RegExp("^" + escaped + "$")
}
