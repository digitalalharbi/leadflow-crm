import { describe, expect, it } from 'vitest'
import { utmSlug, validParamName, withParams } from './hub'

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
