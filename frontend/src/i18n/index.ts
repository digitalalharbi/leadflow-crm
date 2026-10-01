import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import ar from './ar'
import en from './en'

export const LOCALE_KEY = 'leadflow.locale'
export type AppLocale = 'ar' | 'en'

export const DIRECTION: Record<AppLocale, 'rtl' | 'ltr'> = { ar: 'rtl', en: 'ltr' }

function storedLocale(): AppLocale {
  try {
    const saved = localStorage.getItem(LOCALE_KEY)
    if (saved === 'ar' || saved === 'en') return saved
  } catch {
    /* private browsing */
  }

  // Arabic is the default for the first release; the architecture supports both.
  return 'ar'
}

void i18n.use(initReactI18next).init({
  resources: { ar: { translation: ar }, en: { translation: en } },
  lng: storedLocale(),
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
  returnNull: false,
})

/** Applies locale + direction to <html>, which is what drives every RTL style. */
export function applyLocale(locale: AppLocale) {
  void i18n.changeLanguage(locale)

  const html = document.documentElement
  html.lang = locale
  html.dir = DIRECTION[locale]

  try {
    localStorage.setItem(LOCALE_KEY, locale)
  } catch {
    /* ignore */
  }
}

applyLocale(storedLocale())

export default i18n
