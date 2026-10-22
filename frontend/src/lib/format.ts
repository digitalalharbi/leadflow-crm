import { formatDistanceToNowStrict, format, isToday, isYesterday, parseISO } from 'date-fns'
import { ar, enUS } from 'date-fns/locale'

const locales = { ar, en: enUS }

function resolveLocale(lang: string) {
  return lang.startsWith('ar') ? locales.ar : locales.en
}

export function formatDate(iso: string | null | undefined, lang = 'ar'): string {
  if (!iso) return '—'
  return format(parseISO(iso), 'd MMM yyyy', { locale: resolveLocale(lang) })
}

export function formatDateTime(iso: string | null | undefined, lang = 'ar'): string {
  if (!iso) return '—'
  return format(parseISO(iso), 'd MMM yyyy · HH:mm', { locale: resolveLocale(lang) })
}

export function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return format(parseISO(iso), 'HH:mm')
}

/** "3 days ago" / "منذ ٣ أيام", with Today/Yesterday shortcuts. */
export function formatRelative(iso: string | null | undefined, lang = 'ar'): string {
  if (!iso) return '—'

  const date = parseISO(iso)
  const locale = resolveLocale(lang)

  if (isToday(date)) return format(date, 'HH:mm')
  if (isYesterday(date)) return lang.startsWith('ar') ? 'أمس' : 'Yesterday'

  return formatDistanceToNowStrict(date, { addSuffix: true, locale })
}

/**
 * When something last happened, as a label that stands on its own.
 *
 * `formatRelative` renders today as a bare clock, which is right inside a
 * timeline where the day is already established and wrong in a column headed
 * "last lead" — "22:56" there reads as a duration, a count, or nothing at all.
 */
export function formatLastSeen(iso: string | null | undefined, lang = 'ar'): string {
  if (!iso) return '—'

  const date = parseISO(iso)

  if (isToday(date)) {
    return `${lang.startsWith('ar') ? 'اليوم' : 'Today'} ${format(date, 'HH:mm')}`
  }

  return formatRelative(iso, lang)
}

/** How long ago, as a bare duration — "3 دقائق", "2 days" — for a sentence that supplies its own "since". */
export function formatElapsed(iso: string | null | undefined, lang = 'ar'): string {
  if (!iso) return '—'

  return formatDistanceToNowStrict(parseISO(iso), { locale: resolveLocale(lang) })
}

/** Just the clock, for pairing with a relative label that carries the day. */
export function formatClock(iso: string | null | undefined): string {
  return iso ? format(parseISO(iso), 'HH:mm') : '—'
}

/**
 * A due time that says whether it has passed.
 *
 * `formatRelative` renders anything due today as a bare clock time, so an
 * overdue follow-up read as "23:53" — a number, with no hint that it is late.
 * On a work queue, late is the only thing that matters.
 */
export function formatDue(
  iso: string | null | undefined,
  lang = 'ar',
  overdue = false,
): string {
  if (!iso) return '—'

  if (!overdue) return formatRelative(iso, lang)

  return formatDistanceToNowStrict(parseISO(iso), {
    addSuffix: true,
    locale: resolveLocale(lang),
  })
}

/** Money with the tenant's currency, Latin numerals in both locales. */
export function formatCurrency(
  value: number | null | undefined,
  currency = 'SAR',
  lang = 'ar',
): string {
  if (value === null || value === undefined) return '—'

  return new Intl.NumberFormat(lang.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(value)
}

export function formatNumber(value: number | null | undefined, lang = 'ar'): string {
  if (value === null || value === undefined) return '—'

  return new Intl.NumberFormat(lang.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US').format(value)
}

/** A budget range collapses to a single figure when both ends match. */
export function formatBudgetRange(
  min: number | null,
  max: number | null,
  currency = 'SAR',
  lang = 'ar',
): string {
  if (min === null && max === null) return '—'
  if (min !== null && max !== null && min !== max) {
    return `${formatCurrency(min, currency, lang)} – ${formatCurrency(max, currency, lang)}`
  }
  return formatCurrency(max ?? min, currency, lang)
}

/** Builds a wa.me link with the message template filled in. */
export function whatsappLink(
  phoneDigits: string | null,
  template?: string | null,
  vars: Record<string, string> = {},
): string | null {
  if (!phoneDigits) return null

  let text = template ?? ''

  for (const [key, value] of Object.entries(vars)) {
    text = text.replaceAll(`{{${key}}}`, value || '')
  }

  // Never send a raw placeholder to a customer: drop any variable the caller
  // did not supply, then tidy the whitespace it leaves behind.
  text = text
    .replaceAll(/\{\{\s*\w+\s*\}\}/g, '')
    .replaceAll(/[ \t]{2,}/g, ' ')
    .replaceAll(/\s+([،.,!؟?])/g, '$1')
    .trim()

  const base = `https://wa.me/${phoneDigits}`
  return text.trim() ? `${base}?text=${encodeURIComponent(text.trim())}` : base
}

export function telLink(phone: string | null): string | null {
  return phone ? `tel:${phone}` : null
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
}

/** 1.2M, 450K — for a number that has to fit in a card, not a ledger. */
export function formatCompact(value: number | null | undefined, lang = 'ar'): string {
  if (value === null || value === undefined) return '—'

  return new Intl.NumberFormat(lang.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', {
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(value)
}

/** SAR 120K, $42K — money that has to fit in a card. */
export function formatMoneyCompact(value: number | null | undefined, currency = 'SAR', lang = 'ar'): string {
  if (value === null || value === undefined) return '—'

  return new Intl.NumberFormat(lang.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', {
    style: 'currency',
    currency,
    notation: Math.abs(value) >= 10_000 ? 'compact' : 'standard',
    maximumFractionDigits: Math.abs(value) >= 10_000 ? 1 : 0,
  }).format(value)
}
