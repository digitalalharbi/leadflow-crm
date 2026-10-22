/**
 * The few colours the product speaks in.
 *
 * Four tones, because a state is one of four things — fine, needs a look, bad,
 * or neutral — and a fifth colour is a distinction nobody will remember. They
 * resolve to tokens, never to literals, so a white-label workspace's brand and
 * the dark theme both reach every chart and badge without a per-component fix.
 */
export type Tone = 'good' | 'warn' | 'bad' | 'neutral' | 'brand'

export const TONE_COLOR: Record<Tone, string> = {
  good: 'var(--brand)',
  brand: 'var(--brand)',
  warn: 'var(--color-warning)',
  bad: 'var(--color-danger)',
  neutral: 'var(--color-border-strong)',
}

/**
 * A soft fill of the tone, for a chip or an icon disc. Healthy is the kit's pale green with
 * emerald ink; a warning or backlog is its pale copper with dark copper ink (4.5:1 on its fill).
 */
export const TONE_TINT: Record<Tone, string> = {
  good: 'var(--brand-soft)',
  brand: 'var(--brand-soft)',
  warn: 'var(--status-warn-bg)',
  bad: 'color-mix(in srgb, var(--color-danger) 12%, transparent)',
  neutral: 'var(--color-chrome-fill)',
}

/** The ink to put on a tinted tone. */
export const TONE_INK: Record<Tone, string> = {
  good: 'var(--brand-text)',
  brand: 'var(--brand-text)',
  warn: 'var(--status-warn-ink)',
  bad: 'var(--color-danger)',
  neutral: 'var(--color-ink-muted)',
}

/**
 * Shades of the brand, deep to pale, for data that has an order.
 *
 * A funnel or a pipeline reads left to right from "just arrived" to "closed",
 * and a rainbow says nothing about that order while a ramp does. Derived from
 * `--brand` with colour-mix, so it follows the workspace's own colour.
 */
export function brandRamp(count: number): string[] {
  if (count <= 1) return ['var(--brand)']

  return Array.from({ length: count }, (_, index) => {
    // Pale first, deep last: the more advanced the step, the heavier the ink.
    const share = 28 + (index / (count - 1)) * 72

    return `color-mix(in srgb, var(--brand) ${Math.round(share)}%, white)`
  })
}

/** A health score as a tone: the one place a number becomes a verdict. */
export function healthTone(score: number): Tone {
  if (score >= 75) return 'good'
  if (score >= 50) return 'warn'

  return 'bad'
}
