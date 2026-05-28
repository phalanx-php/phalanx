import { readFileSync } from 'fs'
import { partialMatch } from './partial-match.js'

export interface ScriptEntry {
  on: Record<string, unknown>
  respond: Record<string, unknown> | Record<string, unknown>[]
  delay?: number
}

export function loadScript(path: string): ScriptEntry[] {
  const raw = readFileSync(path, 'utf-8')
  const entries = JSON.parse(raw) as ScriptEntry[]
  if (!Array.isArray(entries)) {
    throw new Error(`Script file must be a JSON array, got ${typeof entries}`)
  }
  return entries
}

export function findMatch(entries: ScriptEntry[], msg: Record<string, unknown>): ScriptEntry | null {
  for (const entry of entries) {
    if (partialMatch(entry.on, msg)) {
      return entry
    }
  }
  return null
}

export function resolveVars(template: Record<string, unknown>, source: Record<string, unknown>): Record<string, unknown> {
  const result: Record<string, unknown> = {}
  for (const [key, val] of Object.entries(template)) {
    if (typeof val === 'string' && val.startsWith('$')) {
      const srcKey = val.slice(1)
      result[key] = source[srcKey] ?? val
    } else if (typeof val === 'object' && val !== null && !Array.isArray(val)) {
      result[key] = resolveVars(val as Record<string, unknown>, source)
    } else {
      result[key] = val
    }
  }
  return result
}
