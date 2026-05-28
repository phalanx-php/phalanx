import { NATIVE_HOST_NAME, DAEMON_DEFAULT_PORT } from "./constants"

let nativePort: chrome.runtime.Port | null = null

export async function bootstrapNativeMessaging(): Promise<string | null> {
  return new Promise((resolve) => {
    try {
      nativePort = chrome.runtime.connectNative(NATIVE_HOST_NAME)
    } catch {
      resolve(fallbackUrl())
      return
    }

    const timeout = setTimeout(() => {
      resolve(fallbackUrl())
    }, 5000)

    nativePort.onMessage.addListener((msg: { wsUrl?: string; error?: string }) => {
      clearTimeout(timeout)
      if (msg.wsUrl) {
        resolve(msg.wsUrl)
      } else {
        resolve(fallbackUrl())
      }
    })

    nativePort.onDisconnect.addListener(() => {
      nativePort = null
      // Native host exited. Not fatal -- the WS connection survives independently.
      // Attempt to reconnect native port periodically for keepalive.
      chrome.alarms.create("native-reconnect", { delayInMinutes: 0.5 })
    })

    nativePort.postMessage({})
  })
}

function fallbackUrl(): string {
  const port =
    (typeof process !== "undefined" &&
      process.env.PLASMO_PUBLIC_DAEMON_PORT &&
      parseInt(process.env.PLASMO_PUBLIC_DAEMON_PORT, 10)) ||
    DAEMON_DEFAULT_PORT
  return `ws://localhost:${port}/bridge`
}

export function getNativePort(): chrome.runtime.Port | null {
  return nativePort
}
