import { describe, expect, it } from 'vitest'
import { formatBudgetRange, formatCurrency, formatDue, formatRelative, initials, telLink, whatsappLink } from './format'

describe('whatsappLink', () => {
  it('builds a wa.me link from digits', () => {
    expect(whatsappLink('966512345678')).toBe('https://wa.me/966512345678')
  })

  it('fills the tenant template and encodes it', () => {
    const link = whatsappLink('966512345678', 'مرحباً {{name}}، معك {{agent}} من {{company}}', {
      name: 'سعود',
      agent: 'فهد',
      company: 'رواسي',
    })

    expect(link).toContain('https://wa.me/966512345678?text=')
    expect(decodeURIComponent(link!.split('text=')[1])).toBe('مرحباً سعود، معك فهد من رواسي')
  })

  it('leaves an unfilled placeholder empty rather than printing it', () => {
    const link = whatsappLink('966512345678', 'بخصوص {{project}}', {})
    expect(decodeURIComponent(link!.split('text=')[1])).toBe('بخصوص')
  })

  it('returns null without a number so the button can be hidden', () => {
    expect(whatsappLink(null)).toBeNull()
  })
})

describe('telLink', () => {
  it('builds a tel: link', () => {
    expect(telLink('+966512345678')).toBe('tel:+966512345678')
  })

  it('returns null without a number', () => {
    expect(telLink(null)).toBeNull()
  })
})

describe('formatCurrency', () => {
  it('renders Latin numerals in Arabic so money stays readable', () => {
    const value = formatCurrency(1250000, 'SAR', 'ar')
    expect(value).toMatch(/1,250,000|1٬250٬000/)
    expect(value).not.toMatch(/[٠-٩]/)
  })

  it('shows a dash for a missing value', () => {
    expect(formatCurrency(null)).toBe('—')
  })
})

describe('formatBudgetRange', () => {
  it('collapses to one figure when both ends match', () => {
    expect(formatBudgetRange(500000, 500000, 'SAR', 'en')).not.toContain('–')
  })

  it('shows a range when they differ', () => {
    expect(formatBudgetRange(500000, 900000, 'SAR', 'en')).toContain('–')
  })

  it('shows a dash when the budget is unknown', () => {
    expect(formatBudgetRange(null, null)).toBe('—')
  })
})

describe('initials', () => {
  it('takes the first two words', () => {
    expect(initials('عبدالله الحربي')).toBe('عا')
    expect(initials('Fahad Al Otaibi')).toBe('FA')
  })
})

describe('formatDue', () => {
  it('says how late an overdue follow-up is rather than printing a clock time', () => {
    // formatRelative renders anything due today as "23:53", which on a work
    // queue reads as a number with no hint that it has already passed.
    const anHourAgo = new Date(Date.now() - 3600_000).toISOString()

    expect(formatDue(anHourAgo, 'ar', true)).not.toMatch(/^\d{2}:\d{2}$/)
    expect(formatDue(anHourAgo, 'ar', true).length).toBeGreaterThan(0)
  })

  it('leaves an upcoming follow-up to the usual relative format', () => {
    const inAnHour = new Date(Date.now() + 3600_000).toISOString()

    expect(formatDue(inAnHour, 'ar', false)).toBe(formatRelative(inAnHour, 'ar'))
  })

  it('handles a missing date', () => {
    expect(formatDue(null, 'ar', true)).toBe('—')
  })
})
