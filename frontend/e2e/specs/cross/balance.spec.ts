import { expect, test, type Page } from '@playwright/test'
import { ALPHA } from '../../fixtures/data'
import { apiLogin } from '../../fixtures/api'
import { authenticateAs } from '../../fixtures/pages'
import { resetFixtures } from '../../fixtures/seed'
import { firstLeadId, makeSharedReport } from './helpers'

/**
 * The identity is neutral surfaces, emerald as the accent and copper as the highlight — not a green
 * theme. This measures it: every screen is photographed, each pixel classified by perceptual colour
 * (OKLCH), and a screen that turns green-dominant fails.
 *
 *   green  — a hue between teal and green with real chroma (pine, emerald, soft green)  ≤ 30%
 *   copper — brass hues with real chroma                                                ≤ 5%
 *   the rest is neutral: white, limestone, stone, ink, charcoal                         ≥ 65%
 *
 * Both themes, because the dark theme once built every surface from pine and measured 98% green on every
 * screen while the light one measured 3–6%. The login's split panel is pine by design (half the screen)
 * and is judged on its own, by its form side.
 */
const SCREENS: Array<{ name: string; path: (ctx: { lead: string; share: string }) => string; auth: boolean }> = [
  { name: 'dashboard', path: () => '/', auth: true },
  { name: 'work', path: () => '/work', auth: true },
  { name: 'lead workspace', path: ({ lead }) => `/leads/${lead}`, auth: true },
  { name: 'campaigns', path: () => '/campaigns', auth: true },
  { name: 'reports', path: () => '/reports', auth: true },
  { name: 'access', path: () => '/access', auth: true },
  { name: 'shared report', path: ({ share }) => `/reports/share/${share}`, auth: false },
]

async function shares(page: Page, png: Buffer) {
  return page.evaluate(async (base64) => {
    const img = new Image()
    img.src = `data:image/png;base64,${base64}`
    await img.decode()
    const canvas = document.createElement('canvas')
    canvas.width = img.width
    canvas.height = img.height
    const context = canvas.getContext('2d')!
    context.drawImage(img, 0, 0)
    const data = context.getImageData(0, 0, canvas.width, canvas.height).data
    const lin = (v: number) => { const c = v / 255; return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4 }
    let total = 0
    let green = 0
    let copper = 0

    for (let i = 0; i < data.length; i += 12) {
      const r = lin(data[i]); const g = lin(data[i + 1]); const b = lin(data[i + 2])
      const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b)
      const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b)
      const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b)
      const a = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s
      const bb = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s
      const chroma = Math.hypot(a, bb)
      const hue = (Math.atan2(bb, a) * 180 / Math.PI + 360) % 360

      total++
      if (chroma >= 0.03 && hue >= 135 && hue <= 215) green++
      else if (chroma >= 0.05 && hue >= 55 && hue <= 100) copper++
    }

    return { green: (green / total) * 100, copper: (copper / total) * 100 }
  }, png.toString('base64'))
}

test.describe('Colour balance: neutral surfaces, emerald accent, copper highlight', () => {
  test.beforeAll(async () => {
    resetFixtures()

    // The seeded workspace has colours of its own (a white-label customer). The identity being measured is the
    // platform's, which is what a workspace that chose no colours gets: the emerald and pine of the brand kit.
    const admin = await apiLogin(ALPHA.admin.email)

    try {
      const saved = await admin.context.patch('/api/v1/settings/branding', { data: { primary_color: '#1D5E52', secondary_color: '#0C2A25' } })
      expect(saved.ok()).toBe(true)
    } finally {
      await admin.dispose()
    }
  })

  for (const theme of ['light', 'dark'] as const) {
    test(`${theme} theme: no screen is green-dominant, copper stays a highlight, nothing spills sideways`, async ({ page, context }) => {
      test.setTimeout(240_000)
      const lead = await firstLeadId(ALPHA.admin.email)
      const { token } = await makeSharedReport(ALPHA.admin.email, 'للتوازن')
      const ctx = { lead, share: token }

      await authenticateAs(page, ALPHA.admin.email)
      await page.addInitScript((value) => localStorage.setItem('leadflow.appearance', value), theme)
      const measure = await context.newPage()
      const report: string[] = []

      for (const screen of SCREENS) {
        await page.goto(screen.path(ctx))
        await page.waitForLoadState('networkidle')
        await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]'), undefined, { timeout: 20_000 }).catch(() => undefined)
        await page.waitForTimeout(800)

        const facts = await page.evaluate(() => ({ dir: document.documentElement.dir, dark: document.documentElement.classList.contains('dark'), over: document.documentElement.scrollWidth - document.documentElement.clientWidth }))
        expect(facts.dir, `${screen.name}: right to left`).toBe('rtl')
        expect(facts.dark, `${screen.name}: the ${theme} theme is applied`).toBe(theme === 'dark')
        expect(facts.over, `${screen.name}: nothing scrolls sideways`).toBeLessThanOrEqual(1)

        const { green, copper } = await shares(measure, await page.screenshot())
        report.push(`${screen.name}: green ${green.toFixed(1)}%, copper ${copper.toFixed(1)}%`)

        expect(green, `${screen.name} (${theme}) is green-dominant: ${green.toFixed(1)}%`).toBeLessThanOrEqual(30)
        expect(copper, `${screen.name} (${theme}) overuses copper: ${copper.toFixed(1)}%`).toBeLessThanOrEqual(5)
      }

      test.info().annotations.push({ type: 'balance', description: report.join(' | ') })
    })
  }
})
