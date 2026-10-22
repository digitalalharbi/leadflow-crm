/**
 * Draws the PWA icons and splash images from the مهتم mark (the same geometry as public/favicon.svg),
 * rendering SVG in Chromium so there is no image library to install. Run by hand when the mark changes:
 *
 *   node scripts/make-pwa-icons.mjs
 *
 * Outputs (committed): public/icons/icon-192.png, icon-512.png (rounded tile, purpose "any"),
 * icon-maskable-512.png (full-bleed, mark inside the 80% safe zone), public/apple-touch-icon.png
 * (180px, full-bleed: iOS rounds it itself), and public/splash/<w>x<h>.png for the iOS launch screen.
 */
import { mkdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from '@playwright/test'

const here = path.dirname(fileURLToPath(import.meta.url))
const out = path.resolve(here, '../public')
const TEAL = '#12A594'

// The mark, drawn on a 32×32 grid (see favicon.svg for why its proportions are what they are).
const mark = (stroke, fill) => `
  <g transform="translate(0.158 1.372) scale(0.92)" fill="none" stroke="${stroke}" stroke-width="3.3" stroke-linecap="round" stroke-linejoin="round">
    <path d="M18.96 7.75 A6.9 6.9 0 1 0 21.87 12.80 M13.57 20.15 Q14.00 23.80 9.60 25.60"/>
    <circle cx="24.79" cy="7.75" r="3.2" fill="${fill}" stroke="none"/>
  </g>`

/** A square icon: the tile (rounded or full-bleed) with the mark scaled to `inner` of its width. */
const icon = (size, { rounded, inner }) => {
  const scale = inner
  const offset = (1 - scale) / 2 * 32

  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 32 32">
    <rect width="32" height="32" ${rounded ? 'rx="7.5"' : ''} fill="${TEAL}"/>
    <g transform="translate(${offset} ${offset}) scale(${scale})">${mark('#fff', '#fff')}</g>
  </svg>`
}

/** A launch screen: the mark in teal, centred, on white — what the manifest's background_color promises. */
const splash = (w, h) => {
  const mark_ = Math.round(Math.min(w, h) * 0.26)

  return `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">
    <rect width="${w}" height="${h}" fill="#ffffff"/>
    <svg x="${(w - mark_) / 2}" y="${(h - mark_) / 2 - h * 0.03}" width="${mark_}" height="${mark_}" viewBox="0 0 32 32">
      <rect width="32" height="32" rx="7.5" fill="${TEAL}"/>${mark('#fff', '#fff')}
    </svg>
  </svg>`
}

const jobs = [
  ['icons/icon-192.png', 192, 192, icon(192, { rounded: true, inner: 1 })],
  ['icons/icon-512.png', 512, 512, icon(512, { rounded: true, inner: 1 })],
  // Android masks this to a circle or a squircle: the mark stays inside the central 80%.
  ['icons/icon-maskable-512.png', 512, 512, icon(512, { rounded: false, inner: 0.7 })],
  ['apple-touch-icon.png', 180, 180, icon(180, { rounded: false, inner: 0.78 })],
  // iPhone and iPad launch screens, in device pixels.
  ['splash/1290x2796.png', 1290, 2796, splash(1290, 2796)],
  ['splash/1179x2556.png', 1179, 2556, splash(1179, 2556)],
  ['splash/1170x2532.png', 1170, 2532, splash(1170, 2532)],
  ['splash/1125x2436.png', 1125, 2436, splash(1125, 2436)],
  ['splash/828x1792.png', 828, 1792, splash(828, 1792)],
  ['splash/750x1334.png', 750, 1334, splash(750, 1334)],
  ['splash/1668x2388.png', 1668, 2388, splash(1668, 2388)],
  ['splash/2048x2732.png', 2048, 2732, splash(2048, 2732)],
]

mkdirSync(path.join(out, 'icons'), { recursive: true })
mkdirSync(path.join(out, 'splash'), { recursive: true })

const browser = await chromium.launch()

for (const [file, w, h, svg] of jobs) {
  const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 })
  await page.setContent(`<!doctype html><html><body style="margin:0;background:transparent">${svg}</body></html>`)
  await page.screenshot({ path: path.join(out, file), omitBackground: true, clip: { x: 0, y: 0, width: w, height: h } })
  await page.close()
  console.log('wrote', file)
}

await browser.close()
