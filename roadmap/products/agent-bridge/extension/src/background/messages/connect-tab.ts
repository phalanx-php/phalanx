import type { PlasmoMessaging } from "@plasmohq/messaging"

interface ConnectRequest {
  tabId: number
}

interface ConnectResponse {
  success: boolean
  error?: string
}

// Called from the side panel when user clicks "Connect" on a tab.
// Injects the content script which self-connects via the tab-bridge port.
const handler: PlasmoMessaging.MessageHandler<
  ConnectRequest,
  ConnectResponse
> = async (req, res) => {
  const { tabId } = req.body!

  try {
    // Inject the content script. On success it connects a port named "tab-bridge",
    // which handlePortConnect in index.ts picks up and wires to the daemon.
    await chrome.scripting.executeScript({
      target: { tabId },
      files: ["contents/bridge.js"],
    })

    res.send({ success: true })
  } catch (err) {
    // Injection fails on chrome:// pages, PDF viewers, and other restricted origins
    const message = err instanceof Error ? err.message : "Injection failed"
    console.warn(`[connect-tab] Failed to inject into tab ${tabId}: ${message}`)
    res.send({ success: false, error: message })
  }
}

export default handler
