import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { formatBudgetRange, formatCurrency, formatDue, formatLastSeen, formatRelative, initials, telLink, whatsappLink } from './format'

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
  /*
   * The clock is frozen, because these compare two functions that each read it.
   *
   * `formatDue(..., false)` is `formatRelative`, so they cannot disagree — and
   * they did, in CI, at 23:00. The call is made twice a few microseconds apart,
   * and a date exactly 3,600,000ms out straddles the boundary date-fns rounds
   * on: the first call saw an hour and said "ساعة واحدة", the second saw a
   * hair under and said "60 دقيقة".
   *
   * Only ever near midnight, because at any other hour the date is still today
   * and both return a bare clock. So it was invisible for most of the day and
   * reddened CI for everybody when it was not.
   */
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('says how late an overdue follow-up is rather than printing a clock time', () => {
    vi.setSystemTime(new Date(2026, 0, 15, 10, 0, 0))

    // formatRelative renders anything due today as "23:53", which on a work
    // queue reads as a number with no hint that it has already passed.
    const anHourAgo = new Date(Date.now() - 3600_000).toISOString()

    expect(formatDue(anHourAgo, 'ar', true)).not.toMatch(/^\d{2}:\d{2}$/)
    expect(formatDue(anHourAgo, 'ar', true).length).toBeGreaterThan(0)
  })

  it('leaves an upcoming follow-up to the usual relative format', () => {
    vi.setSystemTime(new Date(2026, 0, 15, 10, 0, 0))

    const inAnHour = new Date(Date.now() + 3600_000).toISOString()

    expect(formatDue(inAnHour, 'ar', false)).toBe(formatRelative(inAnHour, 'ar'))
  })

  /** The branch that broke: an hour from half past eleven at night is tomorrow. */
  it('delegates the same way when the follow-up falls on the next day', () => {
    vi.setSystemTime(new Date(2026, 0, 15, 23, 30, 0))

    const afterMidnight = new Date(Date.now() + 3600_000).toISOString()

    expect(formatDue(afterMidnight, 'ar', false)).toBe(formatRelative(afterMidnight, 'ar'))
    // Not a bare clock: it is no longer today, so the relative wording applies.
    expect(formatDue(afterMidnight, 'ar', false)).not.toMatch(/^\d{2}:\d{2}$/)
  })

  it('handles a missing date', () => {
    expect(formatDue(null, 'ar', true)).toBe('—')
  })
})

describe('formatLastSeen', () => {
  it('never renders today as a bare clock, which reads as a number', () => {
    const now = new Date().toISOString()

    const ar = formatLastSeen(now, 'ar')
    const en = formatLastSeen(now, 'en')

    // The bug this guards: "آخر عميل 22:56" in a column of "منذ 4 أيام".
    expect(ar).not.toMatch(/^\d{2}:\d{2}$/)
    expect(en).not.toMatch(/^\d{2}:\d{2}$/)
    expect(ar).toContain('اليوم')
    expect(en).toContain('Today')
  })

  it('falls back to the relative label for anything older', () => {
    // Frozen for the same reason as `formatDue` above: two clock-reading calls
    // compared to each other will eventually be made either side of a boundary.
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 0, 15, 10, 0, 0))

    const old = new Date(Date.now() - 5 * 86_400_000).toISOString()

    expect(formatLastSeen(old, 'ar')).toBe(formatRelative(old, 'ar'))

    vi.useRealTimers()
  })

  it('says nothing rather than guessing when there is no date', () => {
    expect(formatLastSeen(null)).toBe('—')
  })
})
