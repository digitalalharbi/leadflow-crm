import { useEffect } from 'react'
import { Link, NavLink, useLocation, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { PublicLayout } from '@/components/public/PublicLayout'
import { Rich } from '@/components/public/Rich'
import { LANDING } from '@/content/landing'
import { LEGAL, type LegalKey } from '@/content/legal'
import { PUBLIC_INFO } from '@/content/publicInfo'
import { usePublicMeta } from '@/hooks/usePublicMeta'
import { brandTitle } from '@/lib/brand'
import { cn } from '@/lib/cn'

/**
 * A public information page — privacy, terms, data deletion, security, contact, about — from one template.
 *
 * Text only, by design: nothing here is a form, so a page cannot promise an action the backend does not
 * have. The service's facts (name, mailbox, phone, city, site) come from `publicInfo.ts` through `Rich`.
 */
export function DocumentPage({ page }: { page: LegalKey }) {
  const { i18n } = useTranslation()
  const lang = i18n.language.startsWith('ar') ? 'ar' : 'en'
  const doc = LEGAL[page][lang]
  const location = useLocation()
  const [params] = useSearchParams()
  const highlight = params.get('intent') === 'trial' ? 'trial' : location.hash.replace('#', '')

  usePublicMeta(brandTitle(lang, doc.title), doc.summary, location.pathname, lang)

  // A link to a section (the contact page's "#password") lands on it.
  useEffect(() => {
    const id = location.hash.replace('#', '') || (params.get('intent') === 'trial' ? 'trial' : '')

    if (id) document.getElementById(id)?.scrollIntoView({ block: 'start' })
  }, [location.hash, params, page])

  const nav = <PublicNav />

  return (
    <PublicLayout nav={nav} active={page}>
      <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:py-12" data-testid={`page-${page}`}>
        <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_16rem]">
          <article className="min-w-0 max-w-3xl">
            <h1 className="text-[2rem] font-bold leading-tight sm:text-[2.5rem]" data-testid="doc-title">{doc.title}</h1>
            <p className="mt-2 text-[1.0625rem] leading-relaxed text-[var(--color-ink-muted)]">{doc.summary}</p>
            {(page === 'privacy' || page === 'terms' || page === 'deletion' || page === 'security') && (
              <p className="mt-2 text-[0.9375rem] text-[var(--color-ink-muted)]" data-testid="doc-updated">
                {lang === 'ar' ? 'آخر مراجعة: ' : 'Last reviewed: '}<time dateTime={PUBLIC_INFO.updated} dir="ltr">{PUBLIC_INFO.updated}</time>
              </p>
            )}

            <div className="mt-8 space-y-8">
              {doc.sections.map((section) => (
                <section key={section.id} id={section.id} aria-labelledby={`${section.id}-h`} className={cn('scroll-mt-24 rounded-2xl', highlight === section.id && 'bg-[var(--accent-soft)] p-4 ring-1 ring-[var(--accent)]')}>
                  <h2 id={`${section.id}-h`} className="text-[1.375rem] font-semibold leading-snug">{section.heading}</h2>
                  {section.paragraphs?.map((paragraph, index) => (
                    <p key={index} className="mt-2 text-[1.0625rem] leading-[1.9]"><Rich text={paragraph} /></p>
                  ))}
                  {section.bullets && (
                    <ul className="mt-2 space-y-1.5 ps-5 text-[1.0625rem] leading-[1.8] marker:text-[var(--brand)] [&>li]:list-disc">
                      {section.bullets.map((bullet, index) => <li key={index}><Rich text={bullet} /></li>)}
                    </ul>
                  )}
                </section>
              ))}
            </div>

            <RelatedLinks page={page} lang={lang} />
          </article>

          <aside className="hidden lg:block" aria-label={lang === 'ar' ? 'في هذه الصفحة' : 'On this page'}>
            <nav className="sticky top-24 space-y-1 rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] p-3" data-testid="doc-toc">
              <p className="px-2 pb-1 text-[0.875rem] font-semibold text-[var(--color-ink-muted)]">{lang === 'ar' ? 'في هذه الصفحة' : 'On this page'}</p>
              {doc.sections.map((section) => (
                <a key={section.id} href={`#${section.id}`} className="block rounded-lg px-2 py-1.5 text-[0.9375rem] text-[var(--color-ink-muted)] hover:bg-[var(--color-chrome-fill)] hover:text-[var(--color-ink)]">{section.heading}</a>
              ))}
            </nav>
          </aside>
        </div>
      </div>
    </PublicLayout>
  )
}

function PublicNav() {
  const { i18n } = useTranslation()
  const lang = i18n.language.startsWith('ar') ? 'ar' : 'en'
  const items: Array<[string, string]> = lang === 'ar'
    ? [['/', 'الرئيسية'], ['/about', 'عن مهتم'], ['/security', 'الأمان'], ['/contact', 'التواصل']]
    : [['/', 'Home'], ['/about', 'About'], ['/security', 'Security'], ['/contact', 'Contact']]

  return (
    <>
      {items.map(([to, label]) => (
        <NavLink key={to} to={to} className={({ isActive }) => cn('inline-flex min-h-11 items-center rounded-full px-4 text-[0.9375rem] font-medium hover:bg-[var(--color-chrome-fill)]', isActive && 'bg-[var(--color-chrome-fill)] font-semibold')}>{label}</NavLink>
      ))}
    </>
  )
}

function RelatedLinks({ page, lang }: { page: LegalKey; lang: 'ar' | 'en' }) {
  const copy = LANDING[lang].footer.links
  const all: Array<[LegalKey, string, string]> = [
    ['privacy', '/privacy', copy.privacy], ['terms', '/terms', copy.terms], ['deletion', '/data-deletion', copy.deletion],
    ['security', '/security', copy.security], ['contact', '/contact', copy.contact],
  ]

  return (
    <nav className="mt-12 flex flex-wrap gap-2 border-t border-[var(--color-line)] pt-6" aria-label={lang === 'ar' ? 'صفحات ذات صلة' : 'Related pages'}>
      {all.filter(([key]) => key !== page).map(([key, to, label]) => (
        <Link key={key} to={to} className="pressable inline-flex min-h-11 items-center rounded-full bg-[var(--color-surface)] px-4 text-[0.9375rem] font-medium ring-1 ring-inset ring-[var(--color-line)] hover:bg-[var(--color-chrome-fill)]">{label}</Link>
      ))}
    </nav>
  )
}

export { PublicNav }
