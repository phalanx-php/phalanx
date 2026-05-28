import { WebSocket } from 'ws'
import { parseArgs, requireFlag, flagStr, flagInt, flagBool } from './lib/cli-args.js'
import { tryParse, formatLine, formatNdjson, logInfo, logError } from './lib/message-summary.js'
import { loadScenario, runScenario } from './lib/scenario-runner.js'

const args = parseArgs()
const url = requireFlag(args, 'url')
const scriptPath = flagStr(args, 'script', '')
const clientCount = flagInt(args, 'clients', 1)
const singleMsg = flagStr(args, 'm', '')
const jsonMode = flagBool(args, 'json')

async function connectClient(clientId: number): Promise<void> {
  return new Promise<void>((resolve, reject) => {
    const ws = new WebSocket(url)

    ws.on('open', async () => {
      logInfo(`[client ${clientId}] connected to ${url}`)

      if (singleMsg) {
        const msg = tryParse(singleMsg)
        if (!msg) {
          logError(`Invalid JSON: ${singleMsg}`)
          ws.close()
          reject(new Error('Invalid JSON'))
          return
        }
        ws.send(singleMsg)
        console.log(jsonMode ? formatNdjson('->', msg) : formatLine('->', msg))

        ws.on('message', (data) => {
          const raw = data.toString()
          const recv = tryParse(raw)
          if (recv) {
            console.log(jsonMode ? formatNdjson('<-', recv) : formatLine('<-', recv))
          }
        })

        setTimeout(() => {
          ws.close()
          resolve()
        }, 2000)
        return
      }

      if (scriptPath) {
        try {
          const steps = loadScenario(scriptPath)
          logInfo(`[client ${clientId}] running ${steps.length} scenario steps`)
          await runScenario(ws, steps, jsonMode)
          logInfo(`[client ${clientId}] scenario complete`)
          ws.close()
          resolve()
        } catch (err) {
          logError(`[client ${clientId}] scenario failed: ${(err as Error).message}`)
          ws.close()
          reject(err)
        }
        return
      }

      // No script, no message -- just listen and log
      ws.on('message', (data) => {
        const raw = data.toString()
        const recv = tryParse(raw)
        if (recv) {
          console.log(jsonMode ? formatNdjson('<-', recv) : formatLine('<-', recv))
        }
      })
    })

    ws.on('error', (err) => {
      logError(`[client ${clientId}] connection error: ${err.message}`)
      reject(err)
    })

    ws.on('close', (code) => {
      logInfo(`[client ${clientId}] disconnected (code ${code})`)
    })
  })
}

async function main(): Promise<void> {
  const promises: Promise<void>[] = []
  for (let i = 1; i <= clientCount; i++) {
    promises.push(connectClient(i))
  }

  try {
    await Promise.all(promises)
  } catch {
    process.exit(1)
  }
}

main()
