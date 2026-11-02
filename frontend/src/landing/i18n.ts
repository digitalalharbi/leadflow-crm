import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import { publicPageAr } from '@/i18n/publicPage.ar'
import { publicPageEn } from '@/i18n/publicPage.en'
import { DIRECTION, storedLocale } from '@/i18n/locale'

const locale = storedLocale()

void i18n.use(initReactI18next).init({
  resources: { ar: { translation: { hub: publicPageAr } }, en: { translation: { hub: publicPageEn } } },
  lng: locale,
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
  returnNull: false,
})

document.documentElement.lang = locale
document.documentElement.dir = DIRECTION[locale]
