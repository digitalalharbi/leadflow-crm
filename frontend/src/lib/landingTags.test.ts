import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { isLoadable, loadTags, pushDataLayer, tagSource, trackSubmit } from './landingTags'

type W = Window & { dataLayer?: unknown[]; fbq?: unknown; ttq?: unknown }

beforeEach(() => {
  document.head.querySelectorAll('script[data-mt-tag]').forEach((s) => s.remove())
  const w = window as W
  delete w.dataLayer
  delete w.fbq
  delete w.ttq
})
afterEach(() => vi.restoreAllMocks())

describe('which tags may load', () => {
  it('knows each platform and the one host it loads from', () => {
    expect(tagSource({ kind: 'ga4', id: 'G-ABCD1234' })).toBe('https://www.googletagmanager.com/gtag/js?id=G-ABCD1234')
    expect(tagSource({ kind: 'gtm_web', id: 'GTM-ABC123' })).toBe('https://www.googletagmanager.com/gtm.js?id=GTM-ABC123')
    expect(tagSource({ kind: 'meta', id: '123456789' })).toBe('https://connect.facebook.net/en_US/fbevents.js')
    expect(tagSource({ kind: 'tiktok', id: 'CABCDEFGHIJ' })).toContain('https://analytics.tiktok.com/')
  })

  it('refuses a kind it does not know and an id that could carry anything', () => {
    expect(isLoadable({ kind: 'evil', id: 'G-ABCD1234' })).toBe(false)
    expect(isLoadable({ kind: 'ga4', id: 'G-1"></script><script>alert(1)' })).toBe(false)
    expect(isLoadable({ kind: 'ga4', id: '../x' })).toBe(false)
    expect(isLoadable({ kind: 'webhook', id: 'https://evil.test' })).toBe(false)
    expect(tagSource({ kind: 'ga4', id: 'a b' })).toBeNull()
  })
})

describe('loadTags', () => {
  it('injects each script once, however many times it is asked', () => {
    const tags = [{ kind: 'ga4', id: 'G-ABCD1234' }, { kind: 'meta', id: '123456789' }]
    loadTags(tags)
    loadTags(tags)

    expect(document.head.querySelectorAll('script[data-mt-tag]')).toHaveLength(2)
  })

  it('loads nothing that was not allowed', () => {
    loadTags([{ kind: 'ga4', id: 'bad id' }, { kind: 'nope', id: 'G-ABCD1234' }])

    expect(document.head.querySelectorAll('script[data-mt-tag]')).toHaveLength(0)
  })
})

describe('the dataLayer', () => {
  it('is a plain array the page can push to before any container has loaded', () => {
    pushDataLayer({ event: 'landing_viewed', event_id: 'e1' })

    expect((window as W).dataLayer).toEqual([{ event: 'landing_viewed', event_id: 'e1' }])
  })
})

describe('trackSubmit', () => {
  it('pushes the submission to the dataLayer under the id the server recorded it with', () => {
    trackSubmit([], '8b1d1a7e-0000-4000-8000-000000000001')

    expect((window as W).dataLayer).toContainEqual({ event: 'form_submitted', event_id: '8b1d1a7e-0000-4000-8000-000000000001' })
  })

  it('gives the Meta pixel the same event id, so the platform counts the browser and the server once', () => {
    const fbq = vi.fn()
    ;(window as W).fbq = fbq
    trackSubmit([{ kind: 'meta', id: '123456789' }], 'evt-1')

    expect(fbq).toHaveBeenCalledWith('track', 'Lead', {}, { eventID: 'evt-1' })
  })
})
