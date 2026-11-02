export const LOCALE_KEY = 'leadflow.locale'
export type AppLocale = 'ar' | 'en'

export const DIRECTION: Record<AppLocale, 'rtl' | 'ltr'> = { ar: 'rtl', en: 'ltr' }

export function storedLocale(): AppLocale {
  try {
    const saved = localStorage.getItem(LOCALE_KEY)
    if (saved === 'ar' || saved === 'en') return saved
  } catch {
    /* private browsing */
  }

  // Arabic is the default for the first release; the architecture supports both.
  return 'ar'
}
