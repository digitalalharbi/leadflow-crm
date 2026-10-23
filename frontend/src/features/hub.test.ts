import { describe, expect, it } from 'vitest'
import { isVisible, utmSlug, validParamName, withParams, type FormField } from './hub'

const field = (over: Partial<FormField>): FormField => ({ key: 'a', type: 'text', label: 'A', required: false, hidden: false, mapping: 'none', ...over })

describe('withParams', () => {
  it('appends encoded parameters and leaves the rest of the address alone', () => {
    expect(withParams('https://x.test/p?a=1#top', { utm_source: 'face book', utm_campaign: 'صيف' })).toBe('https://x.test/p?a=1&utm_source=face+book&utm_campaign=%D8%B5%D9%8A%D9%81#top')
  })

  it('skips empty values and never duplicates a parameter', () => {
    expect(withParams('https://x.test/?utm_source=old', { utm_source: 'new', utm_term: '', utm_content: null, utm_medium: undefined })).toBe('https://x.test/?utm_source=new')
  })
})

describe('utmSlug', () => {
  it('turns a campaign name into something a report can group on', () => {
    expect(utmSlug('  Summer Launch — الرياض!! ')).toBe('summer_launch_الرياض')
    expect(utmSlug('---')).toBe('')
  })
})

describe('validParamName', () => {
  it.each([['agent_code', true], ['A1', true], ['1a', false], ['has space', false], ['', false], ['x'.repeat(33), false]])('%s → %s', (name, ok) => {
    expect(validParamName(name)).toBe(ok)
  })
})

describe('isVisible', () => {
  it('shows an ordinary field and never a hidden one', () => {
    expect(isVisible(field({}), {})).toBe(true)
    expect(isVisible(field({ hidden: true }), {})).toBe(false)
    expect(isVisible(field({ type: 'hidden' }), {})).toBe(false)
  })

  it('shows a conditional field only once the other was answered as asked', () => {
    const f = field({ key: 'b', visible_if: { field: 'purpose', equals: 'investment' } })
    expect(isVisible(f, {})).toBe(false)
    expect(isVisible(f, { purpose: 'residence' })).toBe(false)
    expect(isVisible(f, { purpose: 'investment' })).toBe(true)
  })

  it('treats an empty condition value as "any answer", and understands multiple choice', () => {
    expect(isVisible(field({ visible_if: { field: 'x' } }), { x: '' })).toBe(false)
    expect(isVisible(field({ visible_if: { field: 'x' } }), { x: 'anything' })).toBe(true)
    expect(isVisible(field({ visible_if: { field: 'x', equals: 'b' } }), { x: ['a', 'b'] })).toBe(true)
    expect(isVisible(field({ visible_if: { field: 'x', equals: 'c' } }), { x: ['a', 'b'] })).toBe(false)
  })
})
