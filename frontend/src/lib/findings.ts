import type { TFunction } from 'i18next'
import type { Finding } from '@/features/reportShares'

/**
 * A finding, worded in the reader's language from the catalogue (`findings.<key>.finding|impact|action`).
 * The server sends the facts — a key and the numbers — and never a sentence, so the same finding reads
 * right in Arabic and in English, on a dashboard and on a shared report.
 */
export function findingText(t: TFunction, kind: 'finding' | 'impact' | 'action', f: Pick<Finding, 'key' | 'values'>): string {
  return t(`findings.${f.key}.${kind}`, { ...Object.fromEntries(Object.entries(f.values).map(([k, v]) => [k, v ?? '—'])), defaultValue: '' })
}
