#!/usr/bin/env node
/**
 * Keeps the stylesheet and the tested palette in agreement.
 *
 * The colour maths is checked in `palette.test.ts`, against the hexes exported
 * from `palette.ts`. That proves the palette is sound; it does not prove the
 * stylesheet still contains it. Vitest stubs CSS imports, so the test cannot
 * read `index.css` — this can.
 *
 * Without it the two drift: someone nudges a hue in the CSS to match a slide,
 * every test still passes, and the charts ship a palette nothing has ever
 * checked. Which is exactly how the previous one survived for as long as it did.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const here = dirname(fileURLToPath(import.meta.url))
const css = readFileSync(resolve(here, '../src/index.css'), 'utf8')
const ts = readFileSync(resolve(here, '../src/components/charts/palette.ts'), 'utf8')

/** Only real declarations — prose in a comment must not count as one. */
const declared = [...css.matchAll(/^\s*--chart-([1-5]):\s*(#[0-9a-fA-F]{6});/gm)].map((m) => m[2])

function constantFrom(name) {
  const match = ts.match(new RegExp(`export const ${name} = \\[([^\\]]+)\\]`))

  if (!match) {
    throw new Error(`${name} not found in palette.ts`)
  }

  return [...match[1].matchAll(/#[0-9a-fA-F]{6}/g)].map((m) => m[0])
}

const expected = [...constantFrom('LIGHT_HEXES'), ...constantFrom('DARK_HEXES')]

if (declared.length !== expected.length) {
  console.error(
    `✗ chart palette: index.css declares ${declared.length} series, palette.ts lists ${expected.length}.\n` +
      '  Five per theme, light then dark. A sixth series folds into --chart-other.',
  )
  process.exit(1)
}

const drift = declared
  .map((hex, i) => (hex.toLowerCase() === expected[i].toLowerCase() ? null : { i, hex, want: expected[i] }))
  .filter(Boolean)

if (drift.length > 0) {
  console.error('✗ chart palette: index.css and palette.ts disagree.')
  for (const d of drift) {
    const theme = d.i < 5 ? 'light' : 'dark'
    console.error(`  ${theme} --chart-${(d.i % 5) + 1}: stylesheet ${d.hex}, tested ${d.want}`)
  }
  console.error('\n  The tested value is the one that passed the colour checks. If the')
  console.error('  stylesheet is meant to change, change palette.ts too and let the test')
  console.error('  re-check it — a colour nothing measured is how the last palette shipped')
  console.error('  two series a red-blind reader saw as one.')
  process.exit(1)
}

console.log(`✓ chart palette: ${declared.length} declarations match the checked values`)
