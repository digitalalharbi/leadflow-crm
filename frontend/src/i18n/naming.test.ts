import { describe, expect, it } from 'vitest'
import ar from './ar'

/**
 * The Arabic interface is written in Arabic.
 *
 * `temperature` was `{ hot: 'Hot', warm: 'Warm', cold: 'Cold' }` — three
 * English words sitting in a funnel list beside تم التواصل، ردوا، مؤهلون،
 * مواعيد، مبيعات. Nothing was broken, so nothing complained, and it read as a
 * string somebody forgot to translate rather than as a decision.
 *
 * Brand names are not translated: Meta is Meta in every language, and so are
 * the protocol and file-format words a customer has to recognise in a settings
 * field. Everything else is a category label, and a category label is Arabic.
 */
const NOT_TRANSLATED = new Set([
  // Platforms and products, which are names.
  'Meta', 'Facebook', 'Instagram', 'TikTok', 'Snapchat', 'Google Ads', 'LinkedIn', 'GitHub',
  'Google Sheets', 'WhatsApp', 'Webhook', 'CSV', 'SMS', 'API', 'URL', 'ID', 'CRM',
  'Horizon', 'Redis', 'PDF', 'Excel', 'JSON', 'UTM',
])

/** Every leaf string in the bundle, with the key path that reaches it. */
function leaves(node: unknown, path: string[] = []): [string, string][] {
  if (typeof node === 'string') {
    return [[path.join('.'), node]]
  }

  if (node && typeof node === 'object') {
    return Object.entries(node).flatMap(([key, value]) => leaves(value, [...path, key]))
  }

  return []
}

describe('Arabic labels are Arabic', () => {
  const entries = leaves(ar)

  it('reads the whole bundle', () => {
    // A guard that silently walks nothing passes forever.
    expect(entries.length).toBeGreaterThan(500)
  })

  it('has no untranslated category label', () => {
    const latinOnly = entries.filter(([, value]) => {
      const text = value.trim()

      // Interpolation placeholders are code, not prose.
      const prose = text.replace(/\{\{.*?\}\}/g, '').trim()

      if (prose === '' || NOT_TRANSLATED.has(prose)) return false

      // Flag a value that is *entirely* Latin script. A label that mixes an
      // Arabic sentence with a brand name is fine and common.
      return /^[A-Za-z][A-Za-z0-9 .'’&/+-]*$/.test(prose)
    })

    expect(latinOnly.map(([key, value]) => `${key} = ${value}`)).toEqual([])
  })

  /**
   * The interest scale specifically, because it is the one that was wrong and
   * the one a reader meets most often — on every lead row in the product.
   */
  it('states the interest scale as three parallel Arabic levels', () => {
    expect(ar.temperature).toEqual({ hot: 'مرتفع', warm: 'متوسط', cold: 'منخفض' })
  })
})
