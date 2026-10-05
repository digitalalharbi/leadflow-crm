import { describe, expect, it, beforeAll } from 'vitest'
import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import ar from './ar'
import en from './en'

/**
 * Arabic counts six ways. i18next falls back silently when a category is
 * missing — you get the `_other` form, or the raw key, and nothing warns you.
 * The symptom is a sentence that is merely slightly wrong, which is the kind
 * that survives review and ships.
 */
describe('Arabic plural categories', () => {
  beforeAll(async () => {
    await i18n.use(initReactI18next).init({
      resources: { ar: { translation: ar }, en: { translation: en } },
      lng: 'ar',
      fallbackLng: 'en',
      interpolation: { escapeValue: false },
      returnNull: false,
    })
  })

  it.each([
    [0, 'لا عملاء في قائمتك'],
    [1, 'بقي عميل واحد في قائمتك'],
    [2, 'بقي عميلان في قائمتك'],
    [5, 'بقي 5 عملاء في قائمتك'],
    [33, 'بقي 33 عميلًا في قائمتك'],
    [100, 'بقي 100 عميل في قائمتك'],
  ])('renders %i with the right form', (count, expected) => {
    expect(i18n.t('work.remaining', { count })).toBe(expected)
  })

  it('never leaves the raw key visible', () => {
    for (const count of [0, 1, 2, 3, 11, 99, 101, 1000]) {
      const text = i18n.t('work.remaining', { count })
      expect(text).not.toContain('work.remaining')
    }
  })

  it('counts minutes in Arabic too', () => {
    expect(i18n.t('work.overdueBy', { count: 1 })).toBe('متأخر دقيقة واحدة')
    expect(i18n.t('work.overdueBy', { count: 2 })).toBe('متأخر دقيقتين')
    expect(i18n.t('work.overdueBy', { count: 5 })).toBe('متأخر 5 دقائق')
  })
})
