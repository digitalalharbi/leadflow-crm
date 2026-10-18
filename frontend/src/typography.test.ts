/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

// Read as text, not through the CSS pipeline: the contract is about what is
// written in the file. The runner starts in the package root.
const css = readFileSync(resolve(process.cwd(), 'src/index.css'), 'utf-8')
const html = readFileSync(resolve(process.cwd(), 'index.html'), 'utf-8')

/**
 * The typography system is a contract, not a preference: three families, all
 * self-hosted, with technical values in mono and nothing else slipping in.
 */
describe('typography system', () => {
  it('sets one stack for body and headings: Inter for Latin and digits, Noto for Arabic, Plex only as fallback', () => {
    const stack = /'Inter Variable',\s*'Noto Sans Arabic Variable',\s*'IBM Plex Sans Arabic',\s*system-ui,\s*sans-serif;/

    expect(css).toMatch(new RegExp(`--font-body:\\s*${stack.source}`))
    expect(css).toMatch(new RegExp(`--font-heading:\\s*${stack.source}`))
  })

  it('does not ship the fallback face', () => {
    const main = readFileSync(resolve(process.cwd(), 'src/main.tsx'), 'utf-8')

    expect(main).not.toMatch(/ibm-plex-sans-arabic/)
    expect(main).toMatch(/noto-sans-arabic/)
  })

  it('keeps JetBrains Mono for technical values', () => {
    expect(css).toMatch(/--font-mono:\s*'JetBrains Mono',\s*monospace;/)
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
