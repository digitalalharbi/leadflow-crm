/**
 * The chart palette, read from CSS variables so it follows the theme.
 *
 * Deliberately not derived from the tenant's brand colour: a chart whose every
 * series is a tint of one hue cannot be read, and under white label that hue
 * belongs to the customer.
 *
 * ## Why five, and why these five
 *
 * The colours are computed, not chosen. `scripts/validate-chart-palette.mjs`
 * checks every pair for lightness band, chroma floor, colour-vision separation,
 * normal-vision separation and contrast against the surface — and five is the
 * largest set that passes all of them in both themes.
 *
 * The previous palette had eight and failed: `#8b73f5` and `#4f7cff` sat next
 * to each other in the series order at ΔE 7.7 for normal vision and ΔE 1.1 for
 * protanopia. Two adjacent series that nobody could tell apart, and a red-blind
 * reader saw one colour. It had been that way since the charts were written,
 * because nobody had measured it.
 *
 * The hues are Okabe-Ito's, the standard set for colour-vision safety. Only
 * their lightness is re-stepped: Okabe-Ito was designed for bright marks and
 * fails WCAG 3:1 against a near-white surface, and the dark theme is stepped
 * separately rather than lightened, because the warm hues converge as they
 * brighten.
 *
 * ## What it clears, and the one thing it does not
 *
 * Light passes every check outright, contrast included: the closest pair is
 * ΔE 9.2 under simulated protanopia and ΔE 15.0 for normal vision, and the
 * weakest slot is 3.22:1 against a white card. The magenta used to be the
 * exception at 2.97:1, excused because `ChartCard` ships a table view; it was
 * re-stepped down its own hue instead, which cost nothing, because the pair
 * distances that bind this palette are all elsewhere.
 *
 * Dark keeps one exemption and is unlikely ever to lose it. Vermillion cannot
 * reach 3:1 against the dark surface without being brightened into the amber:
 * at the lightness that clears contrast the two sit at ΔE 14.5 for normal
 * vision, under the floor. Contrast is recoverable with a label; two series a
 * reader cannot separate are not. `palette.test.ts` names that slot, so a
 * second one slipping below 3:1 fails the build rather than quietly joining it.
 *
 * The second encoding is not optional either way — every chart carries a
 * legend, four or fewer series are also labelled directly, and fills are
 * separated by a 2px gap.
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
export const LIGHT_HEXES = ['#007b59', '#025e94', '#bf8408', '#ce64a2', '#a64906'] as const
export const DARK_HEXES = ['#048560', '#0178bb', '#bc8207', '#b8528f', '#a63c0c'] as const

/*
 * The surfaces a chart actually sits on, read off the running app rather than
 * assumed: validating against an off-white that the product does not use would
 * be checking a palette nobody ships.
 */
export const LIGHT_SURFACE = '#ffffff'
export const DARK_SURFACE = '#141a26'

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
export const PRIMARY = 'var(--brand)'

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
