import { readFileSync } from 'fs'
import { WebSocket } from 'ws'
import { partialMatch } from './partial-match.js'
import { tryParse, formatLine, formatNdjson, logInfo, logError, type ParsedMessage } from './message-summary.js'

export interface ScenarioStep {
  delay?: number
  send?: Record<string, unknown>
  expect?: Record<string, unknown>
  within?: number
}

export function loadScenario(path: string): ScenarioStep[] {
  const raw = readFileSync(path, 'utf-8')
  const steps = JSON.parse(raw) as ScenarioStep[]
  if (!Array.isArray(steps)) {
    throw new Error(`Scenario file must be a JSON array`)
  }
  return steps
}

export async function runScenario(
  ws: WebSocket,
  steps: ScenarioStep[],
  jsonMode: boolean,
): Promise<void> {
  const pendingExpects: Array<{
    pattern: Record<string, unknown>
    resolve: () => void
    reject: (err: Error) => void
    timer: ReturnType<typeof setTimeout>
  }> = []

  ws.on('message', (data) => {
    const raw = data.toString()
    const msg = tryParse(raw)
    if (msg) {
      console.log(jsonMode ? formatNdjson('<-', msg) : formatLine('<-', msg))

      for (let i = pendingExpects.length - 1; i >= 0; i--) {
        if (partialMatch(pendingExpects[i].pattern, msg)) {
          clearTimeout(pendingExpects[i].timer)
          pendingExpects[i].resolve()
          pendingExpects.splice(i, 1)
        }
      }
    }
  })

  for (const step of steps) {
    if (step.delay) {
      await sleep(step.delay)
    }

    if (step.send) {
      const json = JSON.stringify(step.send)
      ws.send(json)
      const msg = tryParse(json)
      if (msg) {
        console.log(jsonMode ? formatNdjson('->', msg) : formatLine('->', msg))
      }
    }

    if (step.expect) {
      const within = step.within ?? 5000
      await new Promise<void>((resolve, reject) => {
        const timer = setTimeout(() => {
          reject(new Error(`Expected ${JSON.stringify(step.expect)} within ${within}ms -- timed out`))
        }, within)

        pendingExpects.push({ pattern: step.expect!, resolve, reject, timer })
      })
    }
  }
}

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms))
}
