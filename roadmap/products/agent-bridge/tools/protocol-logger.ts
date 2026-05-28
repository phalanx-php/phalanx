import { WebSocketServer, WebSocket } from 'ws'
import { parseArgs, flagStr, flagInt, flagBool } from './lib/cli-args.js'
import { tryParse, formatLine, formatNdjson, logInfo, logError } from './lib/message-summary.js'

const args = parseArgs()
const listenPort = flagInt(args, 'listen', 9079)
const forwardUrl = flagStr(args, 'forward', '')
const jsonMode = flagBool(args, 'json')
const filter = flagStr(args, 'filter', '')

if (!forwardUrl) {
  console.error('Usage: npx tsx protocol-logger.ts --listen <port> --forward <ws-url> [--json] [--filter <type-prefix>]')
  process.exit(1)
}

let clientId = 0

const wss = new WebSocketServer({ port: listenPort })
logInfo(`Listening on ws://localhost:${listenPort}, forwarding to ${forwardUrl}`)

wss.on('connection', (clientWs) => {
  const id = ++clientId
  logInfo(`[client ${id}] connected`)

  const upstream = new WebSocket(forwardUrl)
  let upstreamReady = false
  const pendingMessages: string[] = []

  upstream.on('open', () => {
    upstreamReady = true
    logInfo(`[client ${id}] upstream connected`)
    for (const msg of pendingMessages) {
      upstream.send(msg)
    }
    pendingMessages.length = 0
  })

  upstream.on('error', (err) => {
    logError(`[client ${id}] upstream error: ${err.message}`)
    clientWs.close(1014, 'Upstream unreachable')
  })

  upstream.on('close', (code, reason) => {
    logInfo(`[client ${id}] upstream closed: ${code} ${reason.toString()}`)
    clientWs.close(code, reason.toString())
  })

  upstream.on('message', (data) => {
    const raw = data.toString()
    const msg = tryParse(raw)

    if (msg && shouldLog(msg.type)) {
      console.log(jsonMode ? formatNdjson('<-', msg) : formatLine('<-', msg))
    }

    clientWs.send(raw)
  })

  clientWs.on('message', (data) => {
    const raw = data.toString()
    const msg = tryParse(raw)

    if (msg && shouldLog(msg.type)) {
      console.log(jsonMode ? formatNdjson('->', msg) : formatLine('->', msg))
    }

    if (upstreamReady) {
      upstream.send(raw)
    } else {
      pendingMessages.push(raw)
    }
  })

  clientWs.on('close', () => {
    logInfo(`[client ${id}] disconnected`)
    upstream.close()
  })

  clientWs.on('error', (err) => {
    logError(`[client ${id}] client error: ${err.message}`)
    upstream.close()
  })
})

function shouldLog(type: string): boolean {
  if (!filter) return true
  return type.startsWith(filter)
}

process.on('SIGINT', () => {
  logInfo('Shutting down...')
  wss.close()
  process.exit(0)
})
