export function partialMatch(pattern: Record<string, unknown>, target: Record<string, unknown>): boolean {
  for (const key of Object.keys(pattern)) {
    const pval = pattern[key]
    const tval = target[key]

    if (pval === undefined) continue

    if (typeof pval === 'object' && pval !== null && typeof tval === 'object' && tval !== null) {
      if (!partialMatch(pval as Record<string, unknown>, tval as Record<string, unknown>)) {
        return false
      }
    } else if (pval !== tval) {
      return false
    }
  }
  return true
}
