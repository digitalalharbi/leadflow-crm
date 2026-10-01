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
