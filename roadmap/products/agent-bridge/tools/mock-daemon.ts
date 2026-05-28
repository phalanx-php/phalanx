import { WebSocketServer, WebSocket } from 'ws'
import { createInterface } from 'readline'
import { parseArgs, flagInt, flagBool, flagStr, parseDuration } from './lib/cli-args.js'
import { tryParse, formatLine, formatNdjson, logInfo, logError } from './lib/message-summary.js'
import { loadScript, findMatch, resolveVars, type ScriptEntry } from './lib/script-matcher.js'

const args = parseArgs()
const port = flagInt(args, 'port', 9078)
const scriptPath = flagStr(args, 'script', '')
const interactive = flagBool(args, 'interactive')
const dropAfterStr = flagStr(args, 'drop-after', '')
const dropMode = flagStr(args, 'drop-mode', 'clean') as 'clean' | 'dirty'
const jsonMode = flagBool(args, 'json')

let scriptEntries: ScriptEntry[] = []
if (scriptPath) {
  scriptEntries = loadScript(scriptPath)
  logInfo(`Loaded ${scriptEntries.length} script entries from ${scriptPath}`)
}

const dropAfterMs = dropAfterStr ? parseDuration(dropAfterStr) : 0

const clients = new Map<number, WebSocket>()
let nextClientId = 0

const wss = new WebSocketServer({ port })
logInfo(`Mock daemon listening on ws://localhost:${port}`)

wss.on('connection', (ws) => {
  const id = ++nextClientId
  clients.set(id, ws)
  logInfo(`[connected] client ${id}`)

  if (dropAfterMs > 0) {
    setTimeout(() => {
      logInfo(`[drop] client ${id} after ${dropAfterStr}`)
      if (dropMode === 'dirty') {
        ws.terminate()
      } else {
        ws.close(1000, 'Simulated disconnect')
      }
    }, dropAfterMs)
  }

  ws.on('message', (data) => {
    const raw = data.toString()
    const msg = tryParse(raw)

    if (msg) {
      console.log(jsonMode ? formatNdjson('->', msg) : formatLine('->', msg))

      if (scriptEntries.length > 0) {
        const match = findMatch(scriptEntries, msg)
        if (match) {
          const responses = Array.isArray(match.respond) ? match.respond : [match.respond]
          const delay = match.delay ?? 0
          setTimeout(() => {
            for (const tmpl of responses) {
              const resolved = resolveVars(tmpl, msg)
              const outRaw = JSON.stringify(resolved)
              ws.send(outRaw)
              const outMsg = tryParse(outRaw)
              if (outMsg) {
                console.log(jsonMode ? formatNdjson('<-', outMsg) : formatLine('<-', outMsg))
              }
            }
          }, delay)
        }
      }
    } else {
      logError(`[client ${id}] malformed message: ${raw.slice(0, 100)}`)
    }
  })

  ws.on('close', (code) => {
    clients.delete(id)
    logInfo(`[disconnected] client ${id} (code ${code})`)
  })

  ws.on('error', (err) => {
    logError(`[client ${id}] error: ${err.message}`)
  })
})

if (interactive) {
  const rl = createInterface({ input: process.stdin, output: process.stdout, prompt: '> ' })
  rl.prompt()

  rl.on('line', (line) => {
    const trimmed = line.trim()
    if (!trimmed) { rl.prompt(); return }

    const targetMatch = trimmed.match(/^@(\d+)\s+(.+)$/)
    let targetId: number | null = null
    let json = trimmed

    if (targetMatch) {
      targetId = parseInt(targetMatch[1], 10)
      json = targetMatch[2]
    }

    const msg = tryParse(json)
    if (!msg) {
      logError(`Invalid JSON: ${json.slice(0, 100)}`)
      rl.prompt()
      return
    }

    if (targetId !== null) {
      const ws = clients.get(targetId)
      if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(json)
        console.log(jsonMode ? formatNdjson('<-', msg) : formatLine('<-', msg))
      } else {
        logError(`Client ${targetId} not connected`)
      }
    } else {
      for (const [, ws] of clients) {
        if (ws.readyState === WebSocket.OPEN) ws.send(json)
      }
      console.log(jsonMode ? formatNdjson('<-', msg) : formatLine('<-', msg))
    }

    rl.prompt()
  })
}

process.on('SIGINT', () => {
  logInfo('Shutting down...')
  for (const [, ws] of clients) ws.close(1001, 'Server shutting down')
  wss.close()
  process.exit(0)
})
