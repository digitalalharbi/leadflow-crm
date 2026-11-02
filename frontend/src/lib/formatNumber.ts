export function formatNumber(value: number | null | undefined, lang = 'ar'): string {
  if (value === null || value === undefined) return '—'

  return new Intl.NumberFormat(lang.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US').format(value)
}
