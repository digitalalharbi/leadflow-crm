import { describe, expect, it } from 'vitest'
import ar from './ar'
import en from './en'

/**
 * The product has one name, and it is مهتم.
 *
 * Every name a product has ever been called survives somewhere — a title tag, a
 * toast, a seeder, an email subject — and each one that stays is a customer
 * asking which company they are actually buying from. This is the guard that
 * makes the answer stay one answer.
 *
 * It reads **values**, never keys. `charts.leadFlow` is an identifier that no
 * customer will ever see; `title: 'Lead flow'` was a string that read like the
 * old product name in English, and only the second kind is a branding problem.
 * A test that policed identifiers would be renaming classes forever to satisfy
 * itself.
 */

/*
 * Names the product has been called, or could be mistaken for. Matched as whole
 * words so that ordinary English survives: a chart may describe the "flow" of
 * "leads", and "CRM" on its own is what the thing is, not what it is called.
 */
const FORBIDDEN: Array<[label: string, pattern: RegExp]> = [
  ['LeadFlow', /lead[\s_-]*flow/i],
  ['SalesFlow', /sales[\s_-]*flow/i],
  ['Sales Hub', /sales[\s_-]*hub/i],
  ['Sales Machine', /sales[\s_-]*machine/i],
  ['Lead Machine', /lead[\s_-]*machine/i],
  ['Smart CRM', /smart[\s_-]*crm/i],
  ['Lead CRM', /\blead[\s_-]*crm/i],
  // The reference the GitHub work was modelled on. A good idea to borrow, not
  // a name to inherit.
  ['CampaignsHub', /campaigns?[\s_-]*hub/i],
]

/** Every string a person can read, with the path that produced it. */
function strings(node: unknown, path: string[] = []): Array<[string, string]> {
  if (typeof node === 'string') {
    return [[path.join('.'), node]]
  }

  if (node && typeof node === 'object') {
    return Object.entries(node).flatMap(([key, value]) => strings(value, [...path, key]))
  }

  return []
}

describe('the product has one name', () => {
  it.each([
    ['ar', ar],
    ['en', en],
  ])('%s says nothing the product is not called', (_language, bundle) => {
    const offences = strings(bundle).flatMap(([path, value]) =>
      FORBIDDEN.filter(([, pattern]) => pattern.test(value)).map(
        ([label]) => `${label} in ${path}: "${value}"`,
      ),
    )

    expect(offences).toEqual([])
  })

  it('reads enough of the bundle to be worth believing', () => {
    // A scanner that silently walks nothing passes forever.
    expect(strings(ar).length).toBeGreaterThan(500)
    expect(strings(en).length).toBeGreaterThan(500)
  })

})

/*
 * The product's name is deliberately not in the translations.
 *
 * It is branding, not copy: the login page and the tab show the *tenant's*
 * system name, and مهتم is only what shows before that resolves. So the name
 * lives in the browser chrome and in the one constant that falls back to it,
 * and that is where it has to be checked.
 */
const chrome: Record<string, string> = import.meta.glob(
  ['/index.html', '/public/site.webmanifest', '/src/hooks/useBrandingTheme.ts'],
  { query: '?raw', import: 'default', eager: true },
)

describe('the browser chrome', () => {
  it('reads the files it claims to check', () => {
    expect(Object.keys(chrome).sort()).toEqual([
      '/index.html',
      '/public/site.webmanifest',
      '/src/hooks/useBrandingTheme.ts',
    ])
  })

  it.each(Object.entries(chrome))('%s names the product', (_path, source) => {
    expect(source).toContain('مهتم')
  })

  it.each(Object.entries(chrome))('%s names nothing else', (_path, source) => {
    const offences = FORBIDDEN.filter(([, pattern]) => pattern.test(source)).map(([label]) => label)

    expect(offences).toEqual([])
  })

  /** An installed app keeps whatever the manifest said, so it has to say this. */
  it('ships a manifest whose default identity is the platform', () => {
    const manifest = JSON.parse(chrome['/public/site.webmanifest'])

    expect(manifest.name).toBe('مهتم')
    expect(manifest.short_name).toBe('مهتم')
  })
})
