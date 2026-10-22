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

  /*
   * The bug this pins: a key holding two counts. i18next picks its plural
   * category from a single `count`, so "{{conditions}} شرط · {{actions}} إجراء"
   * silently skipped pluralisation entirely and shipped "0 شرط · 3 إجراء" to
   * the rules list. Counted separately, both read correctly.
   */
  it.each([
    [0, 'بلا شروط'],
    [1, 'شرط واحد'],
    [2, 'شرطان'],
    [3, '3 شروط'],
    [15, '15 شرطًا'],
  ])('counts rule conditions: %i', (count, expected) => {
    expect(i18n.t('automation.conditionCount', { count })).toBe(expected)
  })

  it.each([
    [0, 'بلا إجراءات'],
    [1, 'إجراء واحد'],
    [2, 'إجراءان'],
    [3, '3 إجراءات'],
    [12, '12 إجراءً'],
  ])('counts rule actions: %i', (count, expected) => {
    expect(i18n.t('automation.actionCount', { count })).toBe(expected)
  })

  it('counts the leads a rule preview matched', () => {
    expect(i18n.t('automation.previewMatched', { count: 0 })).toBe('لا تنطبق على أي عميل')
    expect(i18n.t('automation.previewMatched', { count: 1 })).toBe('تنطبق على عميل واحد')
    expect(i18n.t('automation.previewMatched', { count: 43 })).toBe('تنطبق على 43 عميلًا')
    expect(i18n.t('automation.previewSampled', { count: 43 })).toBe('من آخر 43 عميلًا')
  })

  it('counts minutes in Arabic too', () => {
    expect(i18n.t('work.overdueBy', { count: 1 })).toBe('متأخر دقيقة واحدة')
    expect(i18n.t('work.overdueBy', { count: 2 })).toBe('متأخر دقيقتين')
    expect(i18n.t('work.overdueBy', { count: 5 })).toBe('متأخر 5 دقائق')
  })

  /**
   * The first-contact target is configurable, and this heading interpolated it
   * into a fixed `دقيقة`. At the default of 30 that happens to be right, which
   * is why it survived: set the target to 3 or 10 and the dashboard says
   * "3 دقيقة".
   */
  it('agrees with whatever the workspace set its first-contact target to', () => {
    expect(i18n.t('dashboard.awaitingContactList', { count: 1 })).toBe('بانتظار التواصل منذ دقيقة')
    expect(i18n.t('dashboard.awaitingContactList', { count: 2 })).toBe('بانتظار التواصل منذ دقيقتين')
    expect(i18n.t('dashboard.awaitingContactList', { count: 3 })).toBe('بانتظار التواصل منذ 3 دقائق')
    expect(i18n.t('dashboard.awaitingContactList', { count: 10 })).toBe('بانتظار التواصل منذ 10 دقائق')
    expect(i18n.t('dashboard.awaitingContactList', { count: 30 })).toBe('بانتظار التواصل منذ 30 دقيقة')
  })
})
