import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import ar from './ar'
import en from './en'
import { DIRECTION, LOCALE_KEY, storedLocale, type AppLocale } from './locale'

export { DIRECTION, LOCALE_KEY, type AppLocale }

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
