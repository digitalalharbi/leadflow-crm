/**
 * Draws the iPhone and iPad launch screens from the official مهتم logo.
 *
 * The icons themselves (favicon, apple-touch-icon, android-chrome-*, maskable) are the brand kit's own
 * files, copied into public/ unmodified. Only the launch screens are made here, because the kit does not
 * ship them and iOS wants one per device size: the primary stacked logo, centred, on limestone — what the
 * manifest's background_color promises. SVG is rendered in Chromium so there is no image library to install.
 *
 *   node scripts/make-pwa-icons.mjs
 *
 * Outputs (committed): public/splash/<w>x<h>.png.
 */
import { mkdirSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'

const here = path.dirname(fileURLToPath(import.meta.url))
const out = path.resolve(here, '../public')
const LIMESTONE = '#F6F3EE'

// The kit's file, untouched: only its fixed pixel size is dropped so it can be scaled.
const logo = readFileSync(path.resolve(here, '../src/assets/brand/logo/primary/mohtam-stacked-primary.svg'), 'utf8')
  .replace(/ width="[^"]*" height="[^"]*"/, ' width="100%" height="100%"')
const RATIO = 259.4 / 271.7

const splash = (w, h) => {
  const height = Math.round(Math.min(w, h) * 0.3)
  const width = Math.round(height * RATIO)

  return `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">
    <rect width="${w}" height="${h}" fill="${LIMESTONE}"/>
    <svg x="${(w - width) / 2}" y="${(h - height) / 2 - h * 0.03}" width="${width}" height="${height}" viewBox="0 0 259.4 271.7">${logo.replace(/^<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '')}</svg>
  </svg>`
}

const sizes = [[1290, 2796], [1179, 2556], [1170, 2532], [1125, 2436], [828, 1792], [750, 1334], [1668, 2388], [2048, 2732]]

mkdirSync(path.join(out, 'splash'), { recursive: true })

const browser = await chromium.launch()

for (const [w, h] of sizes) {
  const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 })
  await page.setContent(`<!doctype html><html><body style="margin:0">${splash(w, h)}</body></html>`)
  await page.screenshot({ path: path.join(out, `splash/${w}x${h}.png`), clip: { x: 0, y: 0, width: w, height: h } })
  await page.close()
  console.log('wrote', `splash/${w}x${h}.png`)
}

await browser.close()
