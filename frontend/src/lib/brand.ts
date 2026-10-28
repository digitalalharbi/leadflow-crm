import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { create } from 'zustand'

/**
 * The product's identity, in one place.
 *
 * Arabic: مهتم — حوّل الاهتمام إلى فرصة. English: Mohtam — Turn Interest Into Opportunity.
 * Every title the browser shows is `<page> — <brand>`; the separator is always an em dash, never a pipe.
 */
export const BRAND = {
  ar: { name: 'مهتم', tagline: 'حوّل الاهتمام إلى فرصة' },
  en: { name: 'Mohtam', tagline: 'Turn Interest Into Opportunity' },
} as const

export type BrandLang = keyof typeof BRAND

export const TITLE_SEPARATOR = ' — '

export const brandLang = (language: string | undefined): BrandLang => (language?.startsWith('en') ? 'en' : 'ar')

/** `مهتم — حوّل الاهتمام إلى فرصة`, or `<page> — مهتم`. `suffix` replaces the product's name for a white-labelled workspace. */
export function brandTitle(lang: BrandLang, page?: string | null, suffix?: string | null): string {
  const name = suffix || BRAND[lang].name

  return page ? `${page}${TITLE_SEPARATOR}${name}` : `${BRAND[lang].name}${TITLE_SEPARATOR}${BRAND[lang].tagline}`
}

/**
 * The page title a screen asks for. The app shell names every route; a screen about one thing (a project, a report) names
 * that thing instead, and the shell defers to it.
 */
interface TitleState {
  route: string | null
  override: string | null
  suffix: string | null
  set: (patch: Partial<Pick<TitleState, 'route' | 'override' | 'suffix'>>) => void
}

export const useTitleStore = create<TitleState>((set) => ({ route: null, override: null, suffix: null, set: (patch) => set(patch) }))

/** A screen names what it shows (`مشروع رواق`); the browser reads `مشروع رواق — مهتم`. Null leaves the route's name. */
export function useDocumentTitle(page: string | null | undefined): void {
  const set = useTitleStore((s) => s.set)

  useEffect(() => {
    if (!page) return undefined
    set({ override: page })

    return () => set({ override: null })
  }, [page, set])
}

/** Writes `document.title` from the route, the screen's own name and the workspace's name, whenever any of them changes. */
export function useTitleWriter(): void {
  const { i18n } = useTranslation()
  const route = useTitleStore((s) => s.route)
  const override = useTitleStore((s) => s.override)
  const suffix = useTitleStore((s) => s.suffix)

  useEffect(() => {
    const page = override ?? route

    if (page !== null) document.title = brandTitle(brandLang(i18n.language), page, suffix)
  }, [route, override, suffix, i18n.language])
}
