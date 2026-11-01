import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Inbox, TrendingUp, Zap } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Journey } from '@/components/public/Journey'
import { MarketingNav } from '@/components/public/MarketingNav'
import { ProductPreview } from '@/components/public/ProductPreview'
import { PublicLayout } from '@/components/public/PublicLayout'
import { SampleReportDialog } from '@/components/public/SampleReportDialog'
import { LANDING } from '@/content/landing'
import { useEntryAction } from '@/hooks/useEntryAction'
import { usePublicMeta } from '@/hooks/usePublicMeta'
import { BRAND, brandTitle } from '@/lib/brand'

const VALUE_ICONS: LucideIcon[] = [Inbox, Zap, TrendingUp]

/**
 * `/`: the front door — the idea behind مهتم and the way in, on one screen.
 *
 * It sells a promise, not a mechanism: the slogan, what a business gets from it, and the way in. On a laptop
 * (1366×768 and up) the header, the hero, the picture and the short promises fit in the first view, with nothing
 * long beneath them; the details live on /features, /journey and /vision. The picture is sample data and says so.
 * Below the laptop breakpoint the same blocks simply stack.
 */
export function LandingPage() {
  const { i18n } = useTranslation()
  const lang = i18n.language.startsWith('ar') ? 'ar' : 'en'
  const copy = LANDING[lang]
  const [sampleOpen, setSampleOpen] = useState(false)

  const entry = useEntryAction()

  // The one canonical address of the product's front door.
  usePublicMeta(brandTitle(lang), copy.metaDescription, '/', lang)

  return (
    <PublicLayout nav={<MarketingNav />} active="landing" compactFooter>
      <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-3 px-4 py-4 sm:px-6 lg:justify-center lg:gap-3 lg:py-3" data-testid="landing">
        {/* The idea, and a picture of the product. */}
        <section className="grid items-center gap-5 lg:grid-cols-[minmax(0,0.82fr)_minmax(0,1.18fr)] lg:gap-6" aria-labelledby="hero-title">
          <div className="space-y-3 lg:space-y-2.5">
            <p className="text-[1.5rem] font-bold text-[var(--brand-text)]" data-testid="landing-brand">{BRAND[lang].name}</p>
            <p className="inline-flex rounded-full bg-[var(--brand-soft)] px-3.5 py-1 text-[0.9375rem] font-semibold text-[var(--brand-text)]" data-testid="landing-badge">{copy.badge}</p>
            <h1 id="hero-title" className="text-[2.25rem] font-bold leading-[1.15] sm:text-[2.75rem] lg:text-[2.875rem]" data-testid="landing-headline">{copy.headline}</h1>
            <p className="max-w-xl text-[1.0625rem] leading-relaxed text-[var(--color-ink-muted)] lg:text-[1.125rem]" data-testid="landing-sub">{copy.sub}</p>

            <div className="flex flex-wrap items-center gap-2.5">
              {/* Signed in: one button to the dashboard. Not signed in: start, or sign in — both lead to the login. */}
              <Link to={entry.to} className="pressable inline-flex min-h-12 items-center rounded-full bg-[var(--brand)] px-7 text-[1rem] font-semibold text-[var(--brand-contrast)] hover:bg-[var(--brand-hover)]" data-testid="landing-trial" data-authenticated={entry.authenticated}>{entry.authenticated ? copy.cta.dashboard : copy.ctaTrial}</Link>
              {!entry.authenticated && <Link to="/login" className="pressable inline-flex min-h-12 items-center rounded-full bg-[var(--color-surface)] px-7 text-[1rem] font-semibold ring-1 ring-inset ring-[var(--color-border-strong)] hover:bg-[var(--color-chrome-fill)]" data-testid="landing-login">{copy.ctaLogin}</Link>}
            </div>
            {!entry.authenticated && <p className="text-[0.875rem] text-[var(--color-ink-muted)]">{copy.trialNote} <Link to="/contact" className="font-semibold underline underline-offset-4" data-testid="landing-contact">{copy.cta.contact}</Link></p>}

            <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] px-4 py-2" data-testid="landing-journey">
              <p className="text-[0.8125rem] font-semibold text-[var(--color-ink-muted)]">{copy.journeyTitle}</p>
              <Journey steps={copy.journey} testId="landing-journey-steps" />
            </div>
          </div>

          <ProductPreview />
        </section>

        {/* Three promises, and a clear picture for whoever needs it. */}
        <section className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4" aria-label={copy.nav.features} data-testid="landing-values">
          {copy.values.map((value, index) => {
            const Icon = VALUE_ICONS[index]

            return (
              <article key={value.title} className="rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-surface)] p-3" data-testid="value-card">
                <div className="flex items-center gap-2">
                  <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand-text)]" aria-hidden><Icon className="size-4" /></span>
                  <h2 className="text-[1.0625rem] font-semibold">{value.title}</h2>
                </div>
                <p className="mt-1 text-[0.9375rem] leading-snug text-[var(--color-ink-muted)]">{value.body}</p>
              </article>
            )
          })}

          <article className="rounded-[1.25rem] border border-[var(--color-line)] border-t-[3px] border-t-[var(--accent)] bg-[var(--color-surface)] p-3" data-testid="reports-card">
            <h2 className="text-[1.0625rem] font-semibold">{copy.reports.title}</h2>
            <p className="mt-0.5 text-[0.9375rem] leading-snug text-[var(--color-ink-muted)]">{copy.reports.body}</p>
            <button type="button" onClick={() => setSampleOpen(true)} className="pressable mt-0.5 inline-flex min-h-9 items-center text-[0.9375rem] font-semibold text-[var(--brand-text)] underline underline-offset-4" data-testid="sample-report-open">{copy.reports.cta}</button>
          </article>
        </section>
      </div>

      <SampleReportDialog open={sampleOpen} onClose={() => setSampleOpen(false)} />
    </PublicLayout>
  )
}
