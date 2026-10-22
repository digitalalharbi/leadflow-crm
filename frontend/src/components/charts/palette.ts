/**
 * The chart palette, read from CSS variables so it follows the theme.
 *
 * It is the brand kit's own, in the kit's order and never reordered: emerald, copper, info blue,
 * soft green, light copper (`brand/Mohtam-Brand-Kit/05-الألوان-Colors`). Components take their
 * colours from here and never carry one of their own. It is deliberately not derived from the
 * tenant's brand colour: a chart whose every series is a tint of one hue cannot be read, and under
 * white label that hue belongs to the customer.
 *
 * ## What it clears, and what it does not
 *
 * The previous palette was computed to pass every check in `paletteChecks.ts` — a lightness band, a
 * chroma floor, colour-vision separation, normal-vision separation and 3:1 contrast. The kit's is an
 * earthy, deliberately quiet identity palette, and it does not pass all of them. `palette.test.ts`
 * records exactly which it misses, so nothing slips further without the build saying so:
 *
 *  - **Every pair is separable under simulated colour blindness**, in both themes. That is the check
 *    that decides whether a reader can tell two series apart at all, and it holds.
 *  - Under full colour vision three pairs sit between ΔE 11 and 14, against a floor of 15: the two
 *    soft greens, the copper next to the soft green, and the two coppers.
 *  - Four marks are quiet rather than vivid (chroma), and slots 4 and 5 are pale: soft green and light
 *    copper are 2.2:1 and 1.6:1 on a white card, against the 3:1 asked of a graphic.
 *
 * So the second encoding is not optional: every chart carries a legend, four or fewer series are
 * also labelled directly, fills are separated by a 2px gap, and `ChartCard` ships a table view. Dark
 * is stepped separately for the pine card rather than flipped from light — the blue is lifted toward
 * limestone until it separates from the soft green, the emerald is replaced by the lifted soft green.
 */

/**
 * The hexes themselves, mirroring `index.css`.
 *
 * The components use the CSS variables so the theme switches at runtime; these
 * exist so the maths can be checked against the real values in a unit test,
 * and `scripts/check-chart-palette.mjs` fails the build if the stylesheet and
 * this list ever disagree. Two copies with a check between them beats one copy
 * nothing can verify.
 */
export const LIGHT_HEXES = ['#1D5E52', '#B38A55', '#3A6F95', '#8DB9AE', '#E3C99F'] as const
export const DARK_HEXES = ['#8DB9AE', '#C9A26E', '#6990AB', '#2E7D6E', '#E3C99F'] as const

/*
 * The surfaces a chart actually sits on, read off the running app rather than
 * assumed: validating against an off-white that the product does not use would
 * be checking a palette nobody ships.
 */
export const LIGHT_SURFACE = '#ffffff'
export const DARK_SURFACE = '#133B34'

/**
 * Fixed order, never cycled. A sixth series is not a generated hue — it folds
 * into `OTHER` via `foldSeries`, which aggregates the tail into a real bucket
 * rather than painting several series the same colour and hoping.
 */
export const SERIES = [
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-3)',
  'var(--chart-4)',
  'var(--chart-5)',
] as const

/** Everything past the fifth, aggregated. Neutral, because it is not an entity. */
export const OTHER = 'var(--chart-other)'

export const MAX_SERIES = SERIES.length

/** The first series is the tenant's own colour where a chart shows only one. */
export const PRIMARY = 'var(--brand-text)'

/**
 * Status and temperature are a *status* palette, not a categorical one: the
 * colour means the state, it is reserved, and it never stands in for "series
 * four". Each ships beside its own label everywhere it appears.
 */
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

/**
 * The five ad platforms a Saudi developer actually buys, each keeping one
 * colour everywhere it appears — so "the blue one" means Google on the funnel
 * and on the campaign table alike.
 *
 * Sources that are not paid media share the neutral: they are not competing
 * for budget, which is the question every one of these charts is asked.
 */
export const PLATFORM_COLOR: Record<string, string> = {
  meta: SERIES[0],
  facebook: SERIES[0],
  instagram: SERIES[0],
  google_ads: SERIES[1],
  google: SERIES[1],
  tiktok: SERIES[2],
  snapchat: SERIES[3],
  linkedin: SERIES[4],
  website: OTHER,
  webhook: OTHER,
  landing_page: OTHER,
  manual: OTHER,
  import: OTHER,
  csv_import: OTHER,
}

export function platformColor(key: string | null | undefined): string {
  return (key && PLATFORM_COLOR[key]) || OTHER
}

/** A slice of a chart after folding: the tail is one real bucket, not a tint. */
export interface FoldedSlice<T> {
  key: string
  label: string
  value: number
  color: string
  /** True for the aggregate — a legend can say "3 others" rather than "Other". */
  isOther: boolean
  /** What went into the aggregate, so a tooltip or table can still show it. */
  members: T[]
}

/**
 * Keeps the largest `max` and aggregates the rest into one honest bucket.
 *
 * The alternative that ships by accident is `SERIES[i % SERIES.length]`, which
 * gives the sixth series the first one's colour: two entities, one colour, no
 * warning, and a chart that is simply wrong for anybody who reads it. Folding
 * makes the tail visible as a tail.
 */
export function foldSeries<T>(
  items: T[],
  {
    key,
    label,
    value,
    color,
    max = MAX_SERIES,
    otherLabel = 'Other',
  }: {
    key: (item: T) => string
    label: (item: T) => string
    value: (item: T) => number
    color?: (item: T, index: number) => string
    max?: number
    otherLabel?: string
  },
): FoldedSlice<T>[] {
  const sorted = items.toSorted((a, b) => value(b) - value(a))
  const head = sorted.slice(0, max)
  const tail = sorted.slice(max)

  const slices: FoldedSlice<T>[] = head.map((item, index) => ({
    key: key(item),
    label: label(item),
    value: value(item),
    color: color ? color(item, index) : SERIES[index],
    isOther: false,
    members: [item],
  }))

  if (tail.length > 0) {
    slices.push({
      key: '__other__',
      label: otherLabel,
      value: tail.reduce((sum, item) => sum + value(item), 0),
      color: OTHER,
      isOther: true,
      members: tail,
    })
  }

  return slices
}
