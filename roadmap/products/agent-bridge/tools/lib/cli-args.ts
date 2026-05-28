export interface ParsedArgs {
  flags: Record<string, string | true>
  positional: string[]
}

export function parseArgs(argv: string[] = process.argv.slice(2)): ParsedArgs {
  const flags: Record<string, string | true> = {}
  const positional: string[] = []

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i]
    if (arg.startsWith('--')) {
      const key = arg.slice(2)
      const next = argv[i + 1]
      if (next && !next.startsWith('-')) {
        flags[key] = next
        i++
      } else {
        flags[key] = true
      }
    } else if (arg.startsWith('-') && arg.length === 2) {
      const key = arg.slice(1)
      const next = argv[i + 1]
      if (next && !next.startsWith('-')) {
        flags[key] = next
        i++
      } else {
        flags[key] = true
      }
    } else {
      positional.push(arg)
    }
  }

  return { flags, positional }
}

export function requireFlag(args: ParsedArgs, name: string): string {
  const val = args.flags[name]
  if (!val || val === true) {
    console.error(`Missing required flag: --${name}`)
    process.exit(1)
  }
  return val
}

export function flagStr(args: ParsedArgs, name: string, fallback: string): string {
  const val = args.flags[name]
  if (!val || val === true) return fallback
  return val
}

export function flagInt(args: ParsedArgs, name: string, fallback: number): number {
  const val = args.flags[name]
  if (!val || val === true) return fallback
  const n = parseInt(val, 10)
  if (isNaN(n)) {
    console.error(`Flag --${name} must be an integer, got: ${val}`)
    process.exit(1)
  }
  return n
}

export function flagBool(args: ParsedArgs, name: string): boolean {
  return args.flags[name] === true || args.flags[name] === 'true'
}

export function parseDuration(s: string): number {
  const match = s.match(/^(\d+)(ms|s|m)$/)
  if (!match) {
    console.error(`Invalid duration: ${s} (use e.g. 5s, 500ms, 2m)`)
    process.exit(1)
  }
  const n = parseInt(match[1], 10)
  switch (match[2]) {
    case 'ms': return n
    case 's': return n * 1000
    case 'm': return n * 60_000
    default: return n
  }
}
