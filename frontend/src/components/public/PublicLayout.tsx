import { useState, type ReactNode } from 'react'
import { Link, NavLink } from 'react-router-dom'
import { Menu, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Logo } from '@/components/brand'
import { LANDING } from '@/content/landing'
import { applyLocale } from '@/i18n'
import { cn } from '@/lib/cn'
import { useEntryAction } from '@/hooks/useEntryAction'

const language = (lang: string) => (lang.startsWith('ar') ? 'ar' : 'en')

/**
 * The frame every public page shares: the official logo, the way into the product, the language switch,
 * and a short footer. No app chrome — a visitor has no session — and no account-creation control, because
 * there is no self-service sign-up: the call to action is a request for a trial, which is a page.
 */
export function PublicLayout({ children, nav, active, compactFooter = false }: { children: ReactNode; nav?: ReactNode; active?: string; compactFooter?: boolean }) {
  const { i18n } = useTranslation()
  const lang = language(i18n.language)
  const copy = LANDING[lang]
  const [open, setOpen] = useState(false)
  const entry = useEntryAction()

  return (
    <div className="flex min-h-dvh flex-col bg-[var(--color-canvas)] text-[var(--color-ink)]" data-testid="public-page">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-50 focus:rounded-xl focus:bg-[var(--color-surface)] focus:px-4 focus:py-2 focus:font-semibold focus:shadow-[var(--shadow-pop)]">
        {lang === 'ar' ? 'تخطَّ إلى المحتوى' : 'Skip to content'}
      </a>

      <header className="sticky top-0 z-30 border-b border-[var(--color-line)] bg-[var(--color-surface)]/95 pt-[env(safe-area-inset-top)] backdrop-blur" data-testid="public-header">
        <div className="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 sm:px-6">
          <Link to="/" aria-label={lang === 'ar' ? 'مهتم — الرئيسية' : 'مهتم — home'} className="shrink-0 rounded-xl">
            <Logo variant="auto" layout="horizontal" height={44} />
          </Link>

          <nav aria-label={lang === 'ar' ? 'التنقل' : 'Navigation'} className="mx-auto hidden items-center gap-1 lg:flex" data-testid="public-nav">
            {nav}
          </nav>

          <div className="ms-auto flex items-center gap-1.5 lg:ms-0">
            <button
              type="button"
              onClick={() => applyLocale(lang === 'ar' ? 'en' : 'ar')}
              className="pressable hidden min-h-11 rounded-full px-3 text-[0.9375rem] font-medium text-[var(--color-ink-muted)] hover:bg-[var(--color-chrome-fill)] sm:inline-flex sm:items-center"
              data-testid="public-language"
            >
              {lang === 'ar' ? 'English' : 'العربية'}
            </button>
            {/* A visitor signs in; somebody already signed in is offered their dashboard instead — never moved there. */}
            <Link to={entry.to} className="pressable inline-flex min-h-11 items-center rounded-full px-4 text-[0.9375rem] font-semibold hover:bg-[var(--color-chrome-fill)]" data-testid="public-login" data-authenticated={entry.authenticated}>
              {entry.authenticated ? copy.cta.dashboard : copy.nav.login}
            </Link>
            {!entry.authenticated && (
              <Link to="/login" className="pressable hidden min-h-11 items-center rounded-full bg-[var(--brand)] px-5 text-[0.9375rem] font-semibold text-[var(--brand-contrast)] hover:bg-[var(--brand-hover)] sm:inline-flex" data-testid="public-trial">
                {copy.nav.trial}
              </Link>
            )}
            <button
              type="button"
              onClick={() => setOpen((value) => !value)}
              aria-expanded={open}
              aria-controls="public-menu"
              aria-label={copy.nav.menu}
              className="pressable grid size-11 place-items-center rounded-full hover:bg-[var(--color-chrome-fill)] lg:hidden"
              data-testid="public-menu-toggle"
            >
              {open ? <X className="size-5" aria-hidden /> : <Menu className="size-5" aria-hidden />}
            </button>
          </div>
        </div>

        {open && (
          <div id="public-menu" className="border-t border-[var(--color-line)] px-4 py-3 lg:hidden" onClick={() => setOpen(false)}>
            <nav className="grid gap-1" aria-label={copy.nav.menu}>{nav}</nav>
            <div className="mt-2 flex gap-2">
              <Link to={entry.to} className="pressable inline-flex min-h-12 flex-1 items-center justify-center rounded-full bg-[var(--brand)] font-semibold text-[var(--brand-contrast)]" data-testid="public-menu-entry">{entry.authenticated ? copy.cta.dashboard : copy.nav.trial}</Link>
              <button type="button" onClick={() => applyLocale(lang === 'ar' ? 'en' : 'ar')} className="pressable min-h-12 rounded-full px-5 font-medium ring-1 ring-inset ring-[var(--color-line)]">{lang === 'ar' ? 'English' : 'العربية'}</button>
            </div>
          </div>
        )}
      </header>

      <main id="main" className="flex min-h-0 flex-1 flex-col" tabIndex={-1} data-active={active}>
        {children}
      </main>

      <PublicFooter compact={compactFooter} />
    </div>
  )
}

export function PublicFooter({ compact = false }: { compact?: boolean }) {
  const { i18n } = useTranslation()
  const copy = LANDING[language(i18n.language)].footer
  const links: Array<[string, string]> = [
    ['/privacy', copy.links.privacy], ['/terms', copy.links.terms], ['/data-deletion', copy.links.deletion],
    ['/security', copy.links.security], ['/contact', copy.links.contact], ['/about', copy.links.about],
  ]

  return (
    <footer className={cn('border-t border-[var(--color-line)] bg-[var(--color-surface)]', compact ? 'py-1' : 'py-5')} data-testid="public-footer">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 pb-[env(safe-area-inset-bottom)] sm:px-6">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
          <p className="text-[1rem] font-semibold">مهتم</p>
          {!compact && <p className="text-[0.9375rem] text-[var(--color-ink-muted)]">{copy.tagline}</p>}
        </div>
        <nav aria-label={language(i18n.language) === 'ar' ? 'روابط التذييل' : 'Footer links'} className="flex flex-wrap items-center gap-x-1 gap-y-0.5">
          {links.map(([to, label]) => (
            <NavLink key={to} to={to} className={({ isActive }) => cn('inline-flex items-center rounded-lg px-2.5 text-[0.9375rem] hover:underline', compact ? 'min-h-9' : 'min-h-11', isActive ? 'font-semibold text-[var(--color-ink)]' : 'text-[var(--color-ink-muted)]')}>{label}</NavLink>
          ))}
        </nav>
        <p className="text-[0.875rem] text-[var(--color-ink-muted)]">{copy.copyright}</p>
      </div>
    </footer>
  )
}
