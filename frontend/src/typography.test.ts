/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

// Read as text, not through the CSS pipeline: the contract is about what is
// written in the file. The runner starts in the package root.
const css = ['src/styles/foundation.css', 'src/index.css'].map((f) => readFileSync(resolve(process.cwd(), f), 'utf-8')).join('\n')
const html = readFileSync(resolve(process.cwd(), 'index.html'), 'utf-8')

/**
 * The typography system is a contract, not a preference: one family, self-hosted, and nothing else
 * slipping in. The identity's typeface is IBM Plex Sans Arabic (brand kit); technical values use the
 * system monospace, which is never loaded.
 */
describe('typography system', () => {
  it('sets one stack for body and headings: IBM Plex Sans Arabic, which carries Arabic, Latin and every digit', () => {
    const stack = /'IBM Plex Sans Arabic',\s*system-ui,\s*sans-serif;/

    expect(css).toMatch(new RegExp(`--font-body:\\s*${stack.source}`))
    expect(css).toMatch(new RegExp(`--font-heading:\\s*${stack.source}`))
    // The previous identity's faces are gone, not merely demoted in the stack.
    expect(css).not.toMatch(/Inter Variable|Noto Sans Arabic|JetBrains Mono/)
  })

  it('ships the face it names, self-hosted, in the five weights the interface uses', () => {
    const main = readFileSync(resolve(process.cwd(), 'src/fonts.ts'), 'utf-8')

    for (const weight of [300, 400, 500, 600, 700]) {
      expect(main).toMatch(new RegExp(`@fontsource/ibm-plex-sans-arabic/${weight}\\.css`))
    }
    expect(main).not.toMatch(/noto-sans-arabic|fontsource-variable\/inter|jetbrains-mono/)
  })

  it('sets technical values in the system monospace, which is not a loaded font', () => {
    expect(css).toMatch(/--font-mono:\s*ui-monospace,/)
    expect(css).toMatch(/code,\s*kbd,\s*samp,\s*pre,\s*\.font-mono\s*\{[^}]*font-family:\s*var\(--font-mono\)/)
  })

  it('declares font families only through the tokens', () => {
    const declarations = [...css.matchAll(/font-family:\s*([^;]+);/g)].map((m) => m[1].trim())

    for (const value of declarations) {
      expect(value, `font-family: ${value}`).toMatch(/^var\(--font-(body|heading|mono)\)$/)
    }
  })

  it('loads no font from a CDN', () => {
    expect(html).not.toMatch(/fonts\.(googleapis|gstatic)\.com/)
  })

  it('uses tabular numerals for tables, dates and metrics', () => {
    expect(css).toMatch(/table,\s*time,\s*output,\s*\.tabular,\s*\[data-numeric\]\s*\{[^}]*tabular-nums/)
    expect(css).toMatch(/\.t-metric\s*\{[^}]*tabular-nums/)
  })

  it('spends weight 700 only on the page title and the key number', () => {
    const heavy = [...css.matchAll(/(\.t-[a-z-]+|h1)\s*\{[^}]*font-weight:\s*700/g)].map((m) => m[1])

    expect(heavy.sort()).toEqual(['.t-display', '.t-metric', '.t-page-title', 'h1'].sort())
  })
})
