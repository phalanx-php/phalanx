export interface ParsedMessage {
  type: string
  tabId?: number
  [key: string]: unknown
}

export function tryParse(raw: string): ParsedMessage | null {
  try {
    const obj = JSON.parse(raw)
    if (typeof obj === 'object' && obj !== null && typeof obj.type === 'string') {
      return obj as ParsedMessage
    }
    return null
  } catch {
    return null
  }
}

export function summarize(msg: ParsedMessage): string {
  const parts: string[] = [msg.type]

  if (msg.tabId !== undefined) parts.push(`tabId=${msg.tabId}`)

  const t = msg.type
  if (t.startsWith('tab.')) {
    if (msg.domain) parts.push(`domain=${msg.domain}`)
    if (msg.url) parts.push(`url=${truncate(String(msg.url), 60)}`)
  } else if (t === 'dom.snapshot') {
    if (msg.selector) parts.push(`selector=${msg.selector}`)
  } else if (t === 'dom.mutations') {
    const mutations = msg.mutations as unknown[]
    if (Array.isArray(mutations)) parts.push(`count=${mutations.length}`)
  } else if (t === 'dom.request' || t === 'dom.response') {
    if (msg.requestId) parts.push(`requestId=${msg.requestId}`)
    if (t === 'dom.response' && Array.isArray(msg.elements)) {
      parts.push(`elements=${(msg.elements as unknown[]).length}`)
    }
  } else if (t === 'action.execute') {
    if (msg.actionId) parts.push(`actionId=${msg.actionId}`)
    if (Array.isArray(msg.steps)) parts.push(`steps=${(msg.steps as unknown[]).length}`)
  } else if (t === 'action.result') {
    if (msg.actionId) parts.push(`actionId=${msg.actionId}`)
    parts.push(`success=${msg.success}`)
    if (msg.error) parts.push(`error=${msg.error}`)
  } else if (t === 'action.cancel') {
    if (msg.actionId) parts.push(`actionId=${msg.actionId}`)
  } else if (t.startsWith('net.')) {
    if (msg.method) parts.push(`${msg.method}`)
    if (msg.status) parts.push(`${msg.status}`)
    if (msg.url) parts.push(truncate(String(msg.url), 60))
  } else if (t.startsWith('user.')) {
    if (msg.action) parts.push(`action=${msg.action}`)
    if (msg.target) parts.push(`target=${truncate(String(msg.target), 40)}`)
  } else if (t === 'ui.update') {
    if (msg.target) parts.push(`target=${msg.target}`)
  } else if (t === 'flow.throttle') {
    if (msg.maxEventsPerSec !== undefined) parts.push(`maxEventsPerSec=${msg.maxEventsPerSec}`)
  } else if (t === 'flow.pressure') {
    if (msg.bufferDepth !== undefined) parts.push(`bufferDepth=${msg.bufferDepth}`)
  }

  return parts.join('  ')
}

function truncate(s: string, max: number): string {
  return s.length > max ? s.slice(0, max - 3) + '...' : s
}

export function timestamp(): string {
  const d = new Date()
  const h = String(d.getHours()).padStart(2, '0')
  const m = String(d.getMinutes()).padStart(2, '0')
  const s = String(d.getSeconds()).padStart(2, '0')
  const ms = String(d.getMilliseconds()).padStart(3, '0')
  return `${h}:${m}:${s}.${ms}`
}

const RESET = '\x1b[0m'
const GREEN = '\x1b[32m'
const BLUE = '\x1b[34m'
const GRAY = '\x1b[90m'
const YELLOW = '\x1b[33m'
const RED = '\x1b[31m'

export function formatLine(dir: '->' | '<-', msg: ParsedMessage): string {
  const ts = `${GRAY}[${timestamp()}]${RESET}`
  const arrow = dir === '->' ? `${GREEN}->${RESET}` : `${BLUE}<-${RESET}`
  const summary = summarize(msg)
  return `${ts} ${arrow} ${summary}`
}

export function formatNdjson(dir: '->' | '<-', msg: ParsedMessage): string {
  return JSON.stringify({
    ts: new Date().toISOString(),
    dir,
    type: msg.type,
    tabId: msg.tabId,
    raw: msg,
  })
}

export function logError(text: string): void {
  console.error(`${RED}[error]${RESET} ${text}`)
}

export function logInfo(text: string): void {
  console.error(`${YELLOW}[info]${RESET} ${text}`)
}
