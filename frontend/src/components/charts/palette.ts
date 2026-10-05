/**
 * The chart palette, read from CSS variables so it follows the theme.
 *
 * Deliberately not derived from the tenant's brand colour: a chart whose every
 * series is a tint of one hue cannot be read, and under white label that hue
 * belongs to the customer. Only the primary series takes the brand — the rest
 * stay semantic and balanced, which is what keeps a six-series chart legible
 * whichever colour a client picked.
 */
export const SERIES = [
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-3)',
  'var(--chart-4)',
  'var(--chart-5)',
  'var(--chart-6)',
  'var(--chart-7)',
  'var(--chart-8)',
] as const

/** The first series is the tenant's own colour; the rest are ours. */
export const PRIMARY = 'var(--brand)'

export const STATUS_COLOR: Record<string, string> = {
  new: 'var(--color-status-new)',
  contacted: 'var(--color-status-contacted)',
  replied: 'var(--color-status-replied)',
  qualified: 'var(--color-status-qualified)',
  appointment: 'var(--color-status-appointment)',
  negotiation: 'var(--color-status-negotiation)',
  won: 'var(--color-status-won)',
  lost: 'var(--color-status-lost)',
  invalid: 'var(--color-status-invalid)',
  no_response: 'var(--color-status-no_response)',
}

export const TEMPERATURE_COLOR: Record<string, string> = {
  hot: 'var(--color-temp-hot)',
  warm: 'var(--color-temp-warm)',
  cold: 'var(--color-temp-cold)',
}

/** Known platforms keep one colour everywhere they appear. */
export const PLATFORM_COLOR: Record<string, string> = {
  meta: 'var(--chart-2)',
  facebook: 'var(--chart-2)',
  instagram: 'var(--chart-3)',
  snapchat: 'var(--chart-4)',
  tiktok: 'var(--chart-6)',
  google_ads: 'var(--chart-5)',
  google: 'var(--chart-5)',
  linkedin: 'var(--chart-7)',
  website: 'var(--chart-1)',
  webhook: 'var(--chart-1)',
  landing_page: 'var(--chart-3)',
  manual: 'var(--chart-8)',
  import: 'var(--chart-8)',
}

export function platformColor(key: string, fallbackIndex = 0): string {
  return PLATFORM_COLOR[key] ?? SERIES[fallbackIndex % SERIES.length]
}
