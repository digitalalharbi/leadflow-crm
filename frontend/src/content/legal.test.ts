import { describe, expect, it } from 'vitest'
import { LANDING } from './landing'
import { LEGAL, type LegalKey } from './legal'

const KEYS = Object.keys(LEGAL) as LegalKey[]
const everyText = (key: LegalKey, lang: 'ar' | 'en') => LEGAL[key][lang].sections.flatMap((s) => [s.heading, ...(s.paragraphs ?? []), ...(s.bullets ?? [])]).join('\n')

describe('the public pages’ content', () => {
  it.each(KEYS)('%s says the same things in Arabic and English: same sections, same order', (key) => {
    expect(LEGAL[key].en.sections.map((s) => s.id)).toEqual(LEGAL[key].ar.sections.map((s) => s.id))
  })

  it.each(KEYS)('%s has a title, a summary and no empty section', (key) => {
    for (const lang of ['ar', 'en'] as const) {
      const doc = LEGAL[key][lang]

      expect(doc.title.length).toBeGreaterThan(2)
      expect(doc.summary.length).toBeGreaterThan(10)
      for (const section of doc.sections) expect((section.paragraphs?.length ?? 0) + (section.bullets?.length ?? 0), `${key}/${lang}/${section.id}`).toBeGreaterThan(0)
    }
  })

  /** The product is «مهتم» in every language: a Latin spelling is a second name (see the branding specs). */
  it('never spells the product in Latin letters', () => {
    const all = KEYS.flatMap((key) => [everyText(key, 'ar'), everyText(key, 'en')]).join('\n') + JSON.stringify(LANDING)

    expect(all).not.toMatch(/\b(mohtam|mahtam|muhtam|leadflow|campaignshub|influencerhub|appsflyer)\b/i)
  })

  it('invents no company, address or mailbox: the only addresses in the text are the markers the deployment fills', () => {
    for (const key of KEYS) {
      for (const lang of ['ar', 'en'] as const) {
        expect(everyText(key, lang), `${key}/${lang}`).not.toMatch(/[\w.-]+@[\w.-]+\.\w+/)
        expect(everyText(key, lang), `${key}/${lang}`).not.toMatch(/https?:\/\//)
      }
    }
  })
})

describe('privacy policy', () => {
  const ids = LEGAL.privacy.ar.sections.map((s) => s.id)

  it('covers what was asked: data collected, lead data, account data, campaign and tracking data, cookies, integrations, analytics, communications, retention, deletion, security, processors, rights, contact', () => {
    for (const id of ['collect', 'cookies', 'integrations', 'analytics', 'communications', 'retention', 'deletion', 'security', 'processors', 'rights', 'contact']) expect(ids).toContain(id)

    const text = everyText('privacy', 'ar')

    for (const word of ['بيانات الحساب', 'بيانات العملاء المحتملين', 'بيانات الحملات', 'بيانات التتبع']) expect(text).toContain(word)
  })

  it('leaves the legal name, mailbox and address as markers rather than inventing them', () => {
    const contact = LEGAL.privacy.ar.sections.find((s) => s.id === 'contact')!.paragraphs!.join(' ')

    expect(contact).toContain('{company}')
    expect(contact).toContain('{email}')
    expect(contact).toContain('{address}')
  })
})

describe('terms', () => {
  it('covers use, accounts, responsibilities, integrations and third-party APIs, availability, data ownership, acceptable use, suspension, limitation, termination, updates', () => {
    expect(LEGAL.terms.ar.sections.map((s) => s.id)).toEqual(expect.arrayContaining(['service', 'accounts', 'responsibilities', 'integrations', 'availability', 'data', 'acceptable', 'suspension', 'limitation', 'termination', 'updates']))
  })
})

describe('data deletion', () => {
  it('is instructions and a contact path, in four steps — not a form that has nothing behind it', () => {
    const steps = LEGAL.deletion.ar.sections.find((s) => s.id === 'how')!.bullets!

    expect(steps).toHaveLength(4)
    expect(LEGAL.deletion.ar.sections.find((s) => s.id === 'how')!.paragraphs!.join(' ')).toContain('لا يوجد نموذج')
  })
})

describe('security page', () => {
  it('lists the controls the product has — and claims no certification', () => {
    const ids = LEGAL.security.ar.sections.map((s) => s.id)

    expect(ids).toEqual(expect.arrayContaining(['isolation', 'access', 'audit', 'secrets', 'rate', 'sessions']))

    // The certifications may be named only to say they are NOT held.
    for (const lang of ['ar', 'en'] as const) {
      const outside = LEGAL.security[lang].sections.filter((s) => s.id !== 'claims').flatMap((s) => [...(s.paragraphs ?? []), ...(s.bullets ?? [])]).join(' ')

      expect(outside, lang).not.toMatch(/SOC ?2|ISO ?27001|PCI|GDPR/i)
    }

    expect(LEGAL.security.ar.sections.find((s) => s.id === 'claims')!.paragraphs!.join(' ')).toContain('لا نحمل')
    expect(LEGAL.security.en.sections.find((s) => s.id === 'claims')!.paragraphs!.join(' ')).toContain('do not currently hold')
  })

  it('does not assert HTTPS or backups as product features: they are the operating environment’s, said as such', () => {
    const deployment = LEGAL.security.ar.sections.find((s) => s.id === 'deployment')!.paragraphs!.join(' ')

    expect(deployment).toContain('بيئة التشغيل')
  })
})
