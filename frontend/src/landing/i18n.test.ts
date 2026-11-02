import { readFileSync, readdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { publicPageAr } from '@/i18n/publicPage.ar'
import { publicPageEn } from '@/i18n/publicPage.en'

const FORMS = resolve(process.cwd(), 'src/components/forms')
const files = [
  'src/pages/public/LeadPage.tsx', 'src/components/forms/page/LandingPageView.tsx',
  ...readdirSync(FORMS).filter((f) => f.endsWith('.tsx') && !f.includes('.test.')).map((f) => `src/components/forms/${f}`),
]
const has = (dict: unknown, path: string) => path.split('.').reduce<unknown>((at, k) => (at as Record<string, unknown> | undefined)?.[k], dict) !== undefined

describe('the dictionary a landing page loads', () => {
  it('holds every word its components ask for, in both languages', () => {
    const keys = files.flatMap((f) => [...readFileSync(resolve(process.cwd(), f), 'utf-8').matchAll(/\bt\('(hub\.[\w.]+)'/g)].map((m) => m[1]))

    expect(keys.length).toBeGreaterThan(10)
    for (const key of keys) {
      const path = key.replace(/^hub\./, '')

      expect(has(publicPageAr, path), `ar: ${key}`).toBe(true)
      expect(has(publicPageEn, path), `en: ${key}`).toBe(true)
    }
  })

  it('holds the words it looks up by name', () => {
    for (const dict of [publicPageAr, publicPageEn]) {
      for (const key of ['page.cta.whatsapp', 'page.cta.phone', 'page.facts.location', 'public.problem.required', 'public.problem.invalid']) {
        expect(has(dict, key), key).toBe(true)
      }
    }
  })
})

describe('what a landing page imports', () => {
  it('pulls in nothing of the product behind it', () => {
    const banned = /from '(react-router-dom|@tanstack\/react-query|axios|@\/lib\/api|@\/lib\/format|@\/components\/ui|@\/stores\/[\w]+|@\/i18n)'/

    for (const f of [...files, 'src/landing/main.tsx', 'src/landing/i18n.ts', 'src/features/publicLanding.ts']) {
      expect(readFileSync(resolve(process.cwd(), f), 'utf-8'), f).not.toMatch(banned)
    }
  })
})
