// Network observation layer -- runs in the SERVICE WORKER, not content scripts.
// Content scripts cannot access chrome.webRequest. This module listens for
// network events and routes them to the appropriate tab's message stream.

import type { ExtensionMessage } from "./types"

// Track request start times for duration calculation.
// Keyed by chrome.webRequest requestId (string, not our wire protocol requestId).
const requestTimings = new Map<string, { startTime: number; url: string }>()

// Limit timing map size to prevent unbounded growth from requests
// that never complete (e.g., cancelled, aborted, or errored).
const MAX_PENDING_REQUESTS = 1000

export function startNetworkObserver(
  sendForTab: (tabId: number, msg: ExtensionMessage) => void,
  isTabConnected: (tabId: number) => boolean,
): () => void {
  function onBeforeRequest(
    details: chrome.webRequest.WebRequestBodyDetails,
  ): void {
    const tabId = details.tabId
    if (tabId < 0 || !isTabConnected(tabId)) return

    // Evict oldest entries if the map grows too large
    if (requestTimings.size >= MAX_PENDING_REQUESTS) {
      const oldestKey = requestTimings.keys().next().value
      if (oldestKey !== undefined) requestTimings.delete(oldestKey)
    }

    requestTimings.set(details.requestId, {
      startTime: details.timeStamp,
      url: details.url,
    })

    sendForTab(tabId, {
      type: "net.request",
      tabId,
      requestId: details.requestId,
      method: details.method,
      url: details.url,
      timestamp: details.timeStamp,
    })
  }

  function onCompleted(
    details: chrome.webRequest.WebResponseCacheDetails,
  ): void {
    const tabId = details.tabId
    if (tabId < 0 || !isTabConnected(tabId)) return

    const timing = requestTimings.get(details.requestId)
    requestTimings.delete(details.requestId)

    const durationMs = timing
      ? details.timeStamp - timing.startTime
      : 0

    // Extract content-type from response headers if available
    let contentType = ""
    if (details.responseHeaders) {
      const ctHeader = details.responseHeaders.find(
        (h) => h.name.toLowerCase() === "content-type",
      )
      if (ctHeader?.value) contentType = ctHeader.value
    }

    sendForTab(tabId, {
      type: "net.response",
      tabId,
      requestId: details.requestId,
      url: details.url,
      status: details.statusCode,
      contentType,
      durationMs,
      timestamp: details.timeStamp,
    })
  }

  // Clean up timing entries for requests that errored without completing
  function onErrorOccurred(
    details: chrome.webRequest.WebResponseErrorDetails,
  ): void {
    requestTimings.delete(details.requestId)
  }

  // Register listeners. chrome.webRequest is read-only in MV3 --
  // no blocking, no response body access. That's fine for observation.
  const filter: chrome.webRequest.RequestFilter = { urls: ["<all_urls>"] }

  chrome.webRequest.onBeforeRequest.addListener(onBeforeRequest, filter)
  chrome.webRequest.onCompleted.addListener(
    onCompleted,
    filter,
    ["responseHeaders"],
  )
  chrome.webRequest.onErrorOccurred.addListener(onErrorOccurred, filter)

  return () => {
    chrome.webRequest.onBeforeRequest.removeListener(onBeforeRequest)
    chrome.webRequest.onCompleted.removeListener(onCompleted)
    chrome.webRequest.onErrorOccurred.removeListener(onErrorOccurred)
    requestTimings.clear()
  }
}
