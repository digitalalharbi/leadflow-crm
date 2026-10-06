import type { useTranslation } from 'react-i18next'

type Translate = ReturnType<typeof useTranslation>['t']

/**
 * One way to say "how long", for the whole product.
 *
 * There were two, and they disagreed. The velocity chart turned 72 hours into
 * "3 أيام"; the team chart turned the same 72 hours into "72 ساعة", because it
 * only ever stepped from minutes to hours. Two screens, the same lead, two
 * answers — and the reader has no way to tell they are the same number.
 *
 * Arabic has six plural categories, and i18next picks one from a single
 * `count`. So each string carries exactly one number: "ثلاثة أيام وأربع ساعات"
 * cannot be expressed as one key without getting the agreement wrong for some
 * value of one of the two numbers. Rounding to a single unit is not a
 * simplification here — it is the only correct option.
 */
export function humaniseDuration(seconds: number, t: Translate): string {
  const safe = Math.max(0, seconds)

  if (safe < 60) {
    // Not "0 دقيقة". Instant is a fact about the system, and it reads as one.
    return t('charts.duration.lessThanMinute')
  }

  /*
   * Round first, then choose the unit — never the other way round.
   *
   * 59.6 minutes is under the hour, so a check on the raw value picks minutes;
   * rounding then prints "60 دقيقة", one minute past the point where this very
   * function switches to hours. It is a small thing that makes a reader stop
   * trusting the rest of the screen.
   */
  const minutes = Math.round(safe / 60)
  if (minutes < 60) {
    return t('charts.duration.minutes', { count: minutes })
  }

  const hours = Math.round(safe / 3600)
  if (hours < 24) {
    return t('charts.duration.hours', { count: hours })
  }

  const days = Math.round(safe / 86_400)
  if (days < 60) {
    return t('charts.duration.days', { count: days })
  }

  // Past two months, days stop being a unit anybody reads: "412 يومًا" is a
  // number you have to divide before it means anything.
  return t('charts.duration.months', { count: Math.round(days / 30) })
}

/** The same scale, for callers holding hours rather than seconds. */
export function humaniseHours(hours: number, t: Translate): string {
  return humaniseDuration(hours * 3600, t)
}

/** Exported so the tests state the boundaries rather than guess at them. */
export const BOUNDARIES = {
  minute: 60,
  hour: 3600,
  day: 86_400,
  twoMonths: 60 * 86_400,
} as const
