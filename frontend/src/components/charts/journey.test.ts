import { describe, expect, it } from 'vitest'
import { buildJourney } from './LeadJourney'
import { humaniseDuration } from './duration'
import i18n from '@/i18n'

/** Returns the key and the count, so a test can assert on the unit chosen. */
const t = ((key: string, options?: { count?: number }) =>
  options?.count === undefined ? key : `${key}:${options.count}`) as never

const MINUTE = 60
const HOUR = 3600
const DAY = 86_400

describe('saying how long', () => {
  it('does not say "0 minutes"', () => {
    expect(humaniseDuration(0, t)).toBe('charts.duration.lessThanMinute')
    expect(humaniseDuration(59, t)).toBe('charts.duration.lessThanMinute')
  })

  it('steps minutes, hours, days, months', () => {
    expect(humaniseDuration(MINUTE, t)).toBe('charts.duration.minutes:1')
    expect(humaniseDuration(90 * MINUTE, t)).toBe('charts.duration.hours:2')
    expect(humaniseDuration(3 * DAY, t)).toBe('charts.duration.days:3')
    expect(humaniseDuration(90 * DAY, t)).toBe('charts.duration.months:3')
  })

  /**
   * The defect this was written for: choosing the unit from the raw value and
   * then rounding prints "60 دقيقة" at 59.6 minutes — one minute past the
   * point where this very function switches to hours.
   */
  it('never rounds across its own boundary', () => {
    expect(humaniseDuration(59.6 * MINUTE, t)).toBe('charts.duration.hours:1')
    expect(humaniseDuration(23.9 * HOUR, t)).toBe('charts.duration.days:1')
    expect(humaniseDuration(59.9 * DAY, t)).toBe('charts.duration.months:2')
  })

  it('treats a negative duration as none', () => {
    expect(humaniseDuration(-5000, t)).toBe('charts.duration.lessThanMinute')
  })
})

const at = (iso: string) => new Date(iso).toISOString()

describe('building a lead journey', () => {
  const closed = {
    created_at: at('2026-03-01T08:00:00Z'),
    first_contact_at: at('2026-03-01T08:30:00Z'),
    qualified_at: at('2026-03-02T08:30:00Z'),
    won_at: at('2026-03-05T08:30:00Z'),
    lost_at: null,
  }

  it('measures each gap from the milestone before it', () => {
    const journey = buildJourney(closed, new Date('2026-03-10T00:00:00Z'))

    expect(journey.milestones.map((m) => m.key)).toEqual([
      'arrived', 'firstContact', 'qualified', 'won',
    ])
    expect(journey.milestones.map((m) => m.sincePrevious)).toEqual([
      null, 30 * MINUTE, DAY, 3 * DAY,
    ])
  })

  it('stops the clock at the close, whatever the time is now', () => {
    const journey = buildJourney(closed, new Date('2027-01-01T00:00:00Z'))

    expect(journey.waitingSeconds).toBeNull()
    expect(journey.totalSeconds).toBe(4 * DAY + 30 * MINUTE)
  })

  /**
   * An open lead's fourth milestone is "الإغلاق", not "فُقد". Deriving it from
   * `won_at ?? lost_at` would print "lost" under every live lead in the
   * workspace — greyed out, but spelled out, as the thing it is heading for.
   */
  it('does not call an open lead lost', () => {
    const journey = buildJourney(
      { ...closed, won_at: null },
      new Date('2026-03-04T08:30:00Z'),
    )

    expect(journey.milestones.at(-1)?.key).toBe('closed')
    expect(journey.milestones.at(-1)?.at).toBeNull()
    // Two days sitting at "qualified" — the one figure a rep can act on.
    expect(journey.waitingSeconds).toBe(2 * DAY)
  })

  it('counts the wait from the last thing that actually happened', () => {
    const journey = buildJourney(
      {
        created_at: at('2026-03-01T08:00:00Z'),
        first_contact_at: null,
        qualified_at: null,
        won_at: null,
        lost_at: null,
      },
      new Date('2026-03-01T09:00:00Z'),
    )

    expect(journey.waitingSeconds).toBe(HOUR)
    expect(journey.milestones.filter((m) => m.at !== null)).toHaveLength(1)
  })

  /**
   * Timestamps are written by people. A rep marks a lead qualified, then
   * backdates the first call, and the gap goes negative. Drawn, that is a bar
   * pointing the wrong way with a confident number beside it.
   */
  it('refuses to measure a clock that ran backwards', () => {
    const journey = buildJourney(
      {
        created_at: at('2026-03-01T08:00:00Z'),
        first_contact_at: at('2026-03-05T08:00:00Z'),
        qualified_at: at('2026-03-02T08:00:00Z'),
        won_at: null,
        lost_at: null,
      },
      new Date('2026-03-06T08:00:00Z'),
    )

    expect(journey.milestones[1]?.sincePrevious).toBe(4 * DAY)
    expect(journey.milestones[2]?.sincePrevious).toBeNull()
  })

  it('names a lost lead lost, and stops its clock', () => {
    const journey = buildJourney(
      { ...closed, won_at: null, lost_at: at('2026-03-03T08:30:00Z') },
      new Date('2026-03-10T00:00:00Z'),
    )

    expect(journey.milestones.at(-1)?.key).toBe('lost')
    expect(journey.waitingSeconds).toBeNull()
  })

  /** The gaps are the journey; they must not add up to more or less than it. */
  it('the gaps and the wait account for the whole elapsed time', () => {
    const now = new Date('2026-03-04T08:30:00Z')
    const journey = buildJourney({ ...closed, won_at: null }, now)

    const gaps = journey.milestones.reduce((sum, m) => sum + (m.sincePrevious ?? 0), 0)

    expect(gaps + (journey.waitingSeconds ?? 0)).toBe(journey.totalSeconds)
  })
})

/**
 * The tests above use a fake `t` so they can assert on the unit chosen. That
 * proves the arithmetic and nothing about the strings: a missing key renders as
 * the key itself, and every one of those assertions would still pass.
 */
describe('the duration strings actually exist', () => {
  const ar = i18n.getFixedT('ar')

  it('resolves to Arabic, not to the key', () => {
    for (const seconds of [0, MINUTE, 2 * MINUTE, 5 * MINUTE, 40 * MINUTE, HOUR, 2 * HOUR, 5 * HOUR, 20 * HOUR, DAY, 2 * DAY, 5 * DAY, 40 * DAY, 90 * DAY, 400 * DAY]) {
      const text = humaniseDuration(seconds, ar as never)

      expect(text).not.toContain('charts.duration')
      expect(text).toMatch(/[؀-ۿ]/)
    }
  })

  /**
   * Arabic has six plural categories and i18next picks one from `count`. The
   * dual is the one that gets missed: "2 أيام" is wrong where "يومان" is right,
   * and nothing but a human reading it will say so.
   */
  it('uses the dual rather than a number for two', () => {
    expect(humaniseDuration(2 * MINUTE, ar as never)).toBe('دقيقتان')
    expect(humaniseDuration(2 * HOUR, ar as never)).toBe('ساعتان')
    expect(humaniseDuration(2 * DAY, ar as never)).toBe('يومان')
    expect(humaniseDuration(60 * DAY, ar as never)).toBe('شهران')
  })

  it('uses the singular with no digit for one', () => {
    expect(humaniseDuration(MINUTE, ar as never)).toBe('دقيقة')
    expect(humaniseDuration(DAY, ar as never)).toBe('يوم')
  })

  /** Every milestone label the journey can render has a string behind it. */
  it('names every milestone', () => {
    for (const key of ['arrived', 'firstContact', 'qualified', 'won', 'lost', 'closed']) {
      const label = ar(`charts.journey.${key}`)

      expect(label).not.toContain('charts.journey')
      expect(label.length).toBeGreaterThan(1)
    }
  })
})
