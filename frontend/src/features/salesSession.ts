/**
 * The leads a rep has passed over this session, so "Next" never brings back one they just skipped.
 * Kept in session storage: it lives as long as the shift's tab, and is gone with it.
 */
const KEY = 'mahtam.sales.skipped'

export function skippedLeads(): string[] {
  try {
    return JSON.parse(sessionStorage.getItem(KEY) ?? '[]') as string[]
  } catch {
    return []
  }
}

export function skipLead(id: string): string[] {
  const next = [...new Set([...skippedLeads(), id])].slice(-50)

  try {
    sessionStorage.setItem(KEY, JSON.stringify(next))
  } catch {
    /* private window: the list lasts as long as this screen does */
  }

  return next
}
