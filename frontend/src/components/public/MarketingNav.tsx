import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { LANDING } from '@/content/landing'
import { cn } from '@/lib/cn'

/** The marketing header's three pages. Each is its own address — the front door no longer scrolls to sections. */
export const MARKETING_PAGES = ['features', 'journey', 'vision'] as const

export function MarketingNav() {
  const { i18n } = useTranslation()
  const copy = LANDING[i18n.language.startsWith('ar') ? 'ar' : 'en'].nav

  return (
    <>
      {MARKETING_PAGES.map((page) => (
        <NavLink
          key={page}
          to={`/${page}`}
          className={({ isActive }) => cn(
            'inline-flex min-h-11 items-center rounded-full px-4 text-[0.9375rem] font-medium hover:bg-[var(--color-chrome-fill)] hover:text-[var(--color-ink)]',
            isActive ? 'bg-[var(--color-chrome-fill)] font-semibold text-[var(--color-ink)]' : 'text-[var(--color-ink-muted)]',
          )}
          data-testid={`landing-nav-${page}`}
        >
          {copy[page]}
        </NavLink>
      ))}
    </>
  )
}
