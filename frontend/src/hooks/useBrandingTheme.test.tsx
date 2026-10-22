import { beforeEach, describe, expect, it } from 'vitest'
import { renderHook } from '@testing-library/react'
import { PLATFORM_TITLE, useBrandingTheme } from './useBrandingTheme'
import type { Branding } from '@/types/api'

function branding(overrides: Partial<Branding> = {}): Branding {
  return {
    tenant_id: 't1',
    slug: 'acme',
    company_name: 'Acme',
    system_name: 'Acme CRM',
    logo_url: null,
    login_logo_url: null,
    login_background_url: null,
    favicon_url: null,
    primary_color: '#aa3366',
    secondary_color: '#101828',
    support_email: null,
    support_phone: null,
    locale: 'ar',
    currency: 'SAR',
    timezone: 'Asia/Riyadh',
    ...overrides,
  }
}

function icon() {
  return document.querySelector<HTMLLinkElement>("link[rel='icon']")?.getAttribute('href')
}

describe('useBrandingTheme', () => {
  beforeEach(() => {
    document.head.innerHTML =
      '<link rel="icon" href="/favicon.svg"><meta name="theme-color" content="#0C2A25">'
    document.documentElement.removeAttribute('style')
    document.title = 'مهتم'
  })

  it('lets the tenant own the accent, the title and the browser chrome', () => {
    renderHook(() => useBrandingTheme(branding()))

    expect(document.documentElement.style.getPropertyValue('--brand')).toBe('#aa3366')
    expect(document.title).toBe('Acme CRM')
    expect(document.querySelector<HTMLMetaElement>("meta[name='theme-color']")?.content)
      .toBe('#aa3366')
  })

  it("prefers the tenant's own favicon over the platform's", () => {
    renderHook(() => useBrandingTheme(branding({ favicon_url: 'https://cdn.test/acme.png' })))

    expect(icon()).toBe('https://cdn.test/acme.png')
  })

  /*
   * The leak this guards: a branded tenant's icon and title surviving into the
   * next session, so an unbranded customer sees another customer's identity in
   * their own tab.
   */
  it('restores the platform identity for a tenant that has no branding of its own', () => {
    const { rerender } = renderHook(
      ({ b }: { b: Branding }) => useBrandingTheme(b),
      { initialProps: { b: branding({ favicon_url: 'https://cdn.test/acme.png' }) } },
    )

    expect(icon()).toBe('https://cdn.test/acme.png')

    rerender({ b: branding({ system_name: '', favicon_url: null, slug: 'other' }) })

    expect(icon()).toBe('/favicon.svg')
    expect(document.title).toBe(PLATFORM_TITLE)
  })

  it('picks a readable contrast colour rather than assuming white', () => {
    renderHook(() => useBrandingTheme(branding({ primary_color: '#fbe84a' })))

    expect(document.documentElement.style.getPropertyValue('--brand-contrast')).toBe('#16181f')
  })
})
