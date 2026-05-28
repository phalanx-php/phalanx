import type { PlasmoMessaging } from "@plasmohq/messaging"
import { sendToDaemon } from "~lib/ws-manager"
import { removeConnectedTab } from "~lib/state"
import { disconnectTabPort, hasTabPort } from "~lib/router"

interface DisconnectRequest {
  tabId: number
}

interface DisconnectResponse {
  success: boolean
}

// Called from the side panel when user clicks "Disconnect" on a tab.
// Sends tab.disconnect to daemon, removes state, and tears down the content script port.
const handler: PlasmoMessaging.MessageHandler<
  DisconnectRequest,
  DisconnectResponse
> = async (req, res) => {
  const { tabId } = req.body!

  if (hasTabPort(tabId)) {
    sendToDaemon({ type: "tab.disconnect", tabId })
    disconnectTabPort(tabId)
  }

  await removeConnectedTab(tabId)
  res.send({ success: true })
}

export default handler
