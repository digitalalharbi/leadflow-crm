#!/usr/bin/env node
/**
 * Every chart fill must be printable.
 *
 * Browsers drop background colours when printing — deliberately, to save ink.
 * Every bar in this product is a `background` on a div or a span, so without
 * `print-color-adjust: exact` the tracks print and the fills do not. The chart
 * does not look broken on paper; it looks like a month where nothing happened,
 * which is worse.
 *
 * The rule lives in `index.css` and keys off `data-chart-mark`. This checks
 * that every chart component setting an inline background actually carries the
 * attribute — because the next chart written will not remember, and nothing
 * else would notice until somebody printed a report for a meeting.
 */
import { readdirSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join, resolve } from 'node:path'

const here = dirname(fileURLToPath(import.meta.url))
const chartsDir = resolve(here, '../src/components/charts')

/**
 * A tooltip cannot appear in print — it only exists while a pointer is over
 * the chart. Marking it would claim a print behaviour it can never have.
 */
const NEVER_PRINTED = new Set(['ChartTooltip.tsx'])

const offenders = []

for (const file of readdirSync(chartsDir)) {
  if (!file.endsWith('.tsx') || NEVER_PRINTED.has(file)) continue

  const source = readFileSync(join(chartsDir, file), 'utf8')

  // An inline `background:` inside a style prop paints a mark. SVG `fill`
  // prints on its own, so only the CSS ones need the attribute.
  const paints = /\bbackground:\s/.test(source)

  if (paints && !source.includes('data-chart-mark')) {
    offenders.push(file)
  }
}

if (offenders.length > 0) {
  console.error('✗ chart fills that will print blank:')
  for (const file of offenders) console.error(`    ${file}`)
  console.error('\n  Add data-chart-mark to the element whose style sets `background`.')
  console.error('  Browsers strip background colours when printing; the print rule in')
  console.error('  index.css keys off that attribute to put them back.')
  process.exit(1)
}

console.log('✓ chart fills are marked for printing')
