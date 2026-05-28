import { useState, useEffect, useRef } from "react"
import { StatusBar } from "./components/StatusBar"
import { TabConnector } from "./components/TabConnector"

function SidePanel() {
  const [daemonStatus, setDaemonStatus] = useState<
    "online" | "offline" | "reconnecting"
  >("offline")
  const portRef = useRef<chrome.runtime.Port | null>(null)

  useEffect(() => {
    const port = chrome.runtime.connect({ name: "side-panel" })
    portRef.current = port

    port.onMessage.addListener((msg: { type: string; [key: string]: unknown }) => {
      if (msg.type === "ui.update" && msg.target === "status") {
        const data = msg.data as { state?: string }
        if (data.state === "connected") setDaemonStatus("online")
        else if (data.state === "reconnecting") setDaemonStatus("reconnecting")
        else setDaemonStatus("offline")
      }
    })

    port.onDisconnect.addListener(() => {
      portRef.current = null
      setDaemonStatus("offline")
    })

    return () => {
      port.disconnect()
      portRef.current = null
    }
  }, [])

  return (
    <div style={{ fontFamily: "system-ui, sans-serif", padding: "12px" }}>
      <h2 style={{ margin: "0 0 12px 0", fontSize: "16px" }}>
        Phalanx Agent Bridge
      </h2>
      <StatusBar status={daemonStatus} />
      <TabConnector />
    </div>
  )
}

export default SidePanel
