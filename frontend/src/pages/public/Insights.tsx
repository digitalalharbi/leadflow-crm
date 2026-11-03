import { Link, Navigate, useLocation, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { PublicLayout } from '@/components/public/PublicLayout'
import { ContactForm } from '@/components/public/ContactForm'
import { INSIGHTS } from '@/content/insights'
import { usePublicMeta } from '@/hooks/usePublicMeta'
import { brandTitle } from '@/lib/brand'

function useInsights() {
  const { i18n } = useTranslation()
  const lang = i18n.language.startsWith('ar') ? 'ar' as const : 'en' as const

  return { lang, rows: INSIGHTS[lang] }
}

export function InsightsPage() {
  const { t } = useTranslation()
  const { lang, rows } = useInsights()
  const location = useLocation()

  usePublicMeta(brandTitle(lang, t('insightsPage.title')), t('insightsPage.summary'), location.pathname, lang)

  return (
    <PublicLayout>
      <main className="mx-auto max-w-3xl px-4 py-10" data-testid="insights">
        <h1 className="text-[2rem] font-bold" data-testid="page-title">{t('insightsPage.title')}</h1>
        <p className="mt-2 text-[1.0625rem] text-[var(--color-ink-muted)]">{t('insightsPage.summary')}</p>
        <ul className="mt-6 space-y-3">
          {rows.map((row) => (
            <li key={row.slug}>
              <Link to={`/insights/${row.slug}`} className="block rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] p-5 hover:border-[var(--brand)]" data-testid={`insight-${row.slug}`}>
                <h2 className="text-[1.25rem] font-bold">{row.title}</h2>
                <p className="mt-1 text-[var(--color-ink-muted)]">{row.summary}</p>
              </Link>
            </li>
          ))}
        </ul>
        <ContactForm kind="insights" />
      </main>
    </PublicLayout>
  )
}

export function InsightPage() {
  const { t } = useTranslation()
  const { slug } = useParams()
  const { lang, rows } = useInsights()
  const location = useLocation()
  const row = rows.find((r) => r.slug === slug)

  usePublicMeta(brandTitle(lang, row?.title ?? t('insightsPage.title')), row?.summary ?? '', location.pathname, lang)

  if (!row) return <Navigate to="/insights" replace />

  return (
    <PublicLayout>
      <article className="mx-auto max-w-3xl px-4 py-10">
        <Link to="/insights" className="t-caption underline">{t('insightsPage.title')}</Link>
        <h1 className="mt-2 text-[2rem] font-bold leading-tight" data-testid="page-title">{row.title}</h1>
        <p className="mt-2 text-[1.0625rem] text-[var(--color-ink-muted)]">{row.summary}</p>
        {row.sections.map((section) => (
          <section key={section.heading} className="mt-6">
            <h2 className="text-[1.25rem] font-bold">{section.heading}</h2>
            <p className="mt-2 text-[1.0625rem] leading-relaxed">{section.body}</p>
          </section>
        ))}
        <ContactForm kind="demo" />
      </article>
    </PublicLayout>
  )
}
