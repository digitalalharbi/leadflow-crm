import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Check, Compass, Eye, Feather, FileText, Gem, LifeBuoy, Smartphone, Sparkles, Telescope, Users, Zap } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { MarketingNav } from '@/components/public/MarketingNav'
import { PublicLayout } from '@/components/public/PublicLayout'
import { LANDING } from '@/content/landing'
import { MARKETING, type FeatureKey } from '@/content/marketing'
import { useEntryAction } from '@/hooks/useEntryAction'
import { usePublicMeta } from '@/hooks/usePublicMeta'
import { brandTitle } from '@/lib/brand'
import { cn } from '@/lib/cn'

/*
 * /features, /journey and /vision: what a business gets, told visually. Like the front door they sell outcomes and
 * never explain a mechanism — the words live in content/marketing.ts. Motion is decoration only: every animation is
 * skipped for a visitor who asked for reduced motion, and the final state is what assistive technology reads.
 */

const useLang = () => {
  const { i18n } = useTranslation()

  return i18n.language.startsWith('ar') ? 'ar' : 'en'
}

const prefersReducedMotion = () => typeof window === 'undefined' || typeof window.matchMedia !== 'function' || window.matchMedia('(prefers-reduced-motion: reduce)').matches

function MarketingPage({ page, title, sub, children }: { page: 'features' | 'journey' | 'vision'; title: string; sub: string; children: ReactNode }) {
  const lang = useLang()
  usePublicMeta(brandTitle(lang, title), sub, `/${page}`, lang)

  return (
    <PublicLayout nav={<MarketingNav />} active={page}>
      <div className="mx-auto w-full max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:space-y-10 lg:py-12" data-testid={`page-${page}`}>
        <header className="max-w-3xl">
          <h1 className="text-[2rem] font-bold leading-tight sm:text-[2.5rem]" data-testid="marketing-title">{title}</h1>
          <p className="mt-2 text-[1.0625rem] leading-relaxed text-[var(--color-ink-muted)] sm:text-[1.125rem]">{sub}</p>
        </header>
        {children}
        <MarketingCta />
      </div>
    </PublicLayout>
  )
}

function MarketingCta() {
  const lang = useLang()
  const copy = MARKETING[lang].cta
  const landing = LANDING[lang]
  const entry = useEntryAction()

  return (
    <section className="flex flex-col items-center gap-3 rounded-[1.5rem] bg-[var(--brand-soft)] px-4 py-7 text-center" data-testid="marketing-cta">
      <h2 className="text-[1.375rem] font-bold text-[var(--brand-text)]">{copy.title}</h2>
      <p className="max-w-xl text-[1rem] text-[var(--color-ink-muted)]">{copy.body}</p>
      <div className="flex flex-wrap justify-center gap-2.5">
        <Link to={entry.to} className="pressable inline-flex min-h-12 items-center rounded-full bg-[var(--brand)] px-7 font-semibold text-[var(--brand-contrast)] hover:bg-[var(--brand-hover)]" data-testid="marketing-cta-start">{entry.authenticated ? landing.cta.dashboard : landing.cta.start}</Link>
        <Link to="/contact" className="pressable inline-flex min-h-12 items-center rounded-full bg-[var(--color-surface)] px-7 font-semibold ring-1 ring-inset ring-[var(--color-border-strong)] hover:bg-[var(--color-chrome-fill)]" data-testid="marketing-cta-contact">{landing.cta.contact}</Link>
      </div>
    </section>
  )
}

/** Counts up to `value` once, when first seen; shows the value at once when motion is unwelcome, or when it is a ratio (24/7). */
function Counter({ value, suffix = '' }: { value: number; suffix?: string }) {
  const ref = useRef<HTMLSpanElement>(null)
  const [shown, setShown] = useState(() => (suffix || prefersReducedMotion() || typeof IntersectionObserver === 'undefined' ? value : 0))

  useEffect(() => {
    if (shown === value || !ref.current) return

    let frame = 0
    const observer = new IntersectionObserver(([entry]) => {
      if (!entry?.isIntersecting) return
      observer.disconnect()
      const start = performance.now()
      const tick = (now: number) => {
        const progress = Math.min(1, (now - start) / 900)
        setShown(Math.round(value * (1 - (1 - progress) ** 3)))
        if (progress < 1) frame = requestAnimationFrame(tick)
      }
      frame = requestAnimationFrame(tick)
    })
    observer.observe(ref.current)

    return () => {
      observer.disconnect()
      cancelAnimationFrame(frame)
    }
    // Runs once: the count is a one-off flourish.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return <span ref={ref} className="tabular-nums" dir="ltr" aria-label={`${value}${suffix}`}>{shown}{suffix}</span>
}

const FEATURE_ICONS: Record<FeatureKey, LucideIcon> = {
  clarity: Eye, speed: Zap, decisions: Compass, loss: LifeBuoy, reports: FileText, teams: Users, access: Smartphone, experience: Sparkles,
}

/** A tiny, decorative picture of each promise — a shape, not data. */
function Glyph({ kind }: { kind: FeatureKey }) {
  const brand = 'var(--brand)'
  const soft = 'var(--brand-soft)'
  const accent = 'var(--accent)'

  const shapes: Record<FeatureKey, ReactNode> = {
    clarity: [10, 18, 14, 24, 20].map((h, i) => <rect key={i} x={6 + i * 13} y={34 - h} width="8" height={h} rx="2" fill={i === 3 ? accent : brand} opacity={i === 3 ? 1 : 0.35 + i * 0.12} />),
    speed: <><path d="M4 8 C 24 10, 30 28, 70 30" fill="none" stroke={brand} strokeWidth="3" strokeLinecap="round" /><circle cx="70" cy="30" r="4" fill={accent} /></>,
    decisions: <><circle cx="38" cy="20" r="14" fill="none" stroke={soft} strokeWidth="7" /><circle cx="38" cy="20" r="14" fill="none" stroke={brand} strokeWidth="7" strokeDasharray="62 88" transform="rotate(-90 38 20)" strokeLinecap="round" /></>,
    loss: [60, 50, 42, 36].map((w, i) => <rect key={i} x={38 - w / 2} y={3 + i * 9} width={w} height="6" rx="3" fill={i === 3 ? accent : brand} opacity={i === 3 ? 1 : 0.4 + i * 0.15} />),
    reports: <><rect x="20" y="3" width="36" height="34" rx="5" fill={soft} /><rect x="27" y="22" width="5" height="9" rx="1.5" fill={brand} /><rect x="35" y="16" width="5" height="15" rx="1.5" fill={brand} /><rect x="43" y="11" width="5" height="20" rx="1.5" fill={accent} /></>,
    teams: [0, 1, 2].map((i) => <circle key={i} cx={24 + i * 14} cy="20" r="9" fill={i === 1 ? brand : soft} stroke="var(--color-surface)" strokeWidth="2.5" />),
    access: <><circle cx="38" cy="20" r="6" fill={brand} /><circle cx="38" cy="20" r="13" fill="none" stroke={brand} strokeWidth="2" opacity="0.4" /><circle cx="38" cy="20" r="19" fill="none" stroke={brand} strokeWidth="2" opacity="0.18" /></>,
    experience: <path d="M38 5 l4.5 9.5 10.5 1.5 -7.5 7.3 1.8 10.4 -9.3 -4.9 -9.3 4.9 1.8 -10.4 -7.5 -7.3 10.5 -1.5 z" fill={accent} opacity="0.9" />,
  }

  return <svg viewBox="0 0 76 40" className="h-10 w-[4.75rem]" aria-hidden>{shapes[kind]}</svg>
}

export function FeaturesPage() {
  const lang = useLang()
  const copy = MARKETING[lang].features

  return (
    <MarketingPage page="features" title={copy.title} sub={copy.sub}>
      <dl className="grid grid-cols-2 gap-2.5 lg:grid-cols-4" data-testid="features-facts">
        {copy.facts.map((fact) => (
          <div key={fact.label} className="rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-surface)] px-4 py-3.5">
            <dd className="text-[2rem] font-bold leading-none text-[var(--brand-text)] sm:text-[2.25rem]"><Counter value={fact.value} suffix={fact.suffix} /></dd>
            <dt className="mt-1.5 text-[0.9375rem] leading-snug text-[var(--color-ink-muted)]">{fact.label}</dt>
          </div>
        ))}
      </dl>

      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="features-grid">
        {copy.items.map((item) => {
          const Icon = FEATURE_ICONS[item.key]

          return (
            <li
              key={item.key}
              className="group flex flex-col gap-3 rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-surface)] p-4 transition-[transform,box-shadow,border-color] duration-200 hover:border-[var(--brand)] hover:shadow-[0_12px_30px_-18px_rgb(20_32_29/0.35)] motion-safe:hover:-translate-y-0.5"
              data-testid="feature-value"
            >
              <div className="flex items-center justify-between gap-2">
                <span className="grid size-10 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand-text)] transition-transform duration-200 motion-safe:group-hover:scale-110" aria-hidden><Icon className="size-5" /></span>
                <Glyph kind={item.key} />
              </div>
              <div>
                <h2 className="text-[1.125rem] font-semibold">{item.title}</h2>
                <p className="mt-1 text-[0.9375rem] leading-snug text-[var(--color-ink-muted)]">{item.body}</p>
              </div>
            </li>
          )
        })}
      </ul>
    </MarketingPage>
  )
}

export function JourneyPage() {
  const lang = useLang()
  const copy = MARKETING[lang].journey
  const stages = copy.stages
  const [active, setActive] = useState(0)
  const [touched, setTouched] = useState(false)
  const tabs = useRef<Array<HTMLButtonElement | null>>([])

  // Walks the journey once by itself until the visitor takes over; never for a visitor who asked for less motion.
  useEffect(() => {
    if (touched || prefersReducedMotion()) return
    const timer = window.setInterval(() => setActive((index) => (index + 1) % stages.length), 3200)

    return () => window.clearInterval(timer)
  }, [touched, stages.length])

  const choose = (index: number, focus = false) => {
    setTouched(true)
    setActive(index)
    if (focus) tabs.current[index]?.focus()
  }

  const onKey = (event: KeyboardEvent<HTMLButtonElement>) => {
    const forward = document.documentElement.dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight'
    const back = forward === 'ArrowLeft' ? 'ArrowRight' : 'ArrowLeft'
    const moves: Record<string, number> = { [forward]: active + 1, [back]: active - 1, ArrowDown: active + 1, ArrowUp: active - 1, Home: 0, End: stages.length - 1 }

    if (event.key in moves) {
      event.preventDefault()
      choose((moves[event.key]! + stages.length) % stages.length, true)
    }
  }

  const stage = stages[active]!
  const progress = (active / (stages.length - 1)) * 100

  return (
    <MarketingPage page="journey" title={copy.title} sub={copy.sub}>
      <section className="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-surface)] p-4 sm:p-6" data-testid="journey-map">
        <div className="relative">
          {/* The road, and how far along it this stage is. Drawn behind the nodes, from the reading edge. */}
          <div className="absolute inset-x-[8.33%] top-6 hidden h-1 rounded-full bg-[var(--color-surface-sunken)] sm:block" aria-hidden>
            <div className="absolute inset-y-0 start-0 rounded-full bg-[var(--brand)] transition-[width] duration-500 ease-out" style={{ width: `${progress}%` }} data-testid="journey-progress" />
          </div>

          <div role="tablist" aria-label={copy.title} className="relative grid grid-cols-2 gap-2 sm:grid-cols-6 sm:gap-1">
            {stages.map((item, index) => {
              const selected = index === active
              const reached = index <= active

              return (
                <button
                  key={item.title}
                  ref={(el) => { tabs.current[index] = el }}
                  type="button"
                  role="tab"
                  id={`journey-tab-${index}`}
                  aria-selected={selected}
                  aria-controls="journey-panel"
                  tabIndex={selected ? 0 : -1}
                  onClick={() => choose(index)}
                  onKeyDown={onKey}
                  className={cn('pressable flex min-h-12 items-center gap-2 rounded-2xl px-2 py-2 text-start sm:flex-col sm:gap-2 sm:text-center', selected ? 'bg-[var(--brand-soft)] sm:bg-transparent' : 'hover:bg-[var(--color-chrome-fill)] sm:hover:bg-transparent')}
                  data-testid="journey-stage"
                  data-reached={reached}
                >
                  <span
                    className={cn(
                      'grid size-12 shrink-0 place-items-center rounded-full border-2 text-[1rem] font-bold transition-[background-color,color,border-color,transform] duration-300',
                      reached ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-contrast)]' : 'border-[var(--color-line)] bg-[var(--color-surface)] text-[var(--color-ink-muted)]',
                      selected && 'motion-safe:scale-110 ring-4 ring-[var(--brand-soft)]',
                      index === stages.length - 1 && reached && 'border-[var(--accent)] bg-[var(--accent)]',
                    )}
                    aria-hidden
                  >
                    {index + 1}
                  </span>
                  <span className={cn('text-[0.9375rem] leading-tight', selected ? 'font-bold text-[var(--color-ink)]' : 'font-medium text-[var(--color-ink-muted)]')}>{item.title}</span>
                </button>
              )
            })}
          </div>
        </div>

        <div role="tabpanel" id="journey-panel" aria-labelledby={`journey-tab-${active}`} aria-live="polite" className="mt-5 grid gap-3 rounded-[1.25rem] bg-[var(--color-canvas)] p-4 sm:grid-cols-[auto_1fr] sm:items-center sm:gap-5 sm:p-5" data-testid="journey-panel">
          <p className="text-[0.875rem] font-semibold text-[var(--color-ink-muted)]">
            {copy.progress.replace('{n}', String(active + 1)).replace('{total}', String(stages.length))}
          </p>
          <div key={active} className="motion-safe:animate-rise">
            <h2 className="text-[1.375rem] font-bold">{stage.title} — <span className="text-[var(--brand-text)]">{stage.promise}</span></h2>
            <p className="mt-1 text-[1.0625rem] leading-relaxed text-[var(--color-ink-muted)]">{stage.outcome}</p>
          </div>
        </div>
      </section>

      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="journey-values">
        {copy.values.map((value) => (
          <li key={value.title} className="rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-surface)] p-4">
            <h2 className="flex items-center gap-2 text-[1.0625rem] font-semibold"><Check className="size-4 text-[var(--brand-text)]" aria-hidden />{value.title}</h2>
            <p className="mt-1 text-[0.9375rem] leading-snug text-[var(--color-ink-muted)]">{value.body}</p>
          </li>
        ))}
      </ul>
    </MarketingPage>
  )
}

const PILLAR_ICONS: LucideIcon[] = [Telescope, Gem, Feather]

export function VisionPage() {
  const lang = useLang()
  const copy = MARKETING[lang].vision

  return (
    <MarketingPage page="vision" title={copy.title} sub={copy.closing}>
      <blockquote className="rounded-[1.5rem] border-s-4 border-[var(--accent)] bg-[var(--color-surface)] px-5 py-6 text-[1.25rem] font-semibold leading-relaxed sm:px-8 sm:text-[1.5rem]" data-testid="vision-statement">
        {copy.statement}
      </blockquote>

      <ul className="grid gap-3 md:grid-cols-3" data-testid="vision-pillars">
        {copy.pillars.map((pillar, index) => {
          const Icon = PILLAR_ICONS[index]!

          return (
            <li key={pillar.title} className="rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-surface)] p-5">
              <span className="grid size-11 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand-text)]" aria-hidden><Icon className="size-5" /></span>
              <h2 className="mt-3 text-[1.25rem] font-bold">{pillar.title}</h2>
              <p className="mt-1 text-[1rem] leading-relaxed text-[var(--color-ink-muted)]">{pillar.body}</p>
            </li>
          )
        })}
      </ul>

      <section aria-labelledby="vision-why" className="rounded-[1.5rem] border border-[var(--color-line)] bg-[var(--color-surface)] p-5 sm:p-6">
        <h2 id="vision-why" className="text-[1.375rem] font-bold">{copy.whyTitle}</h2>
        <ul className="mt-3 grid gap-2.5 sm:grid-cols-2" data-testid="vision-why">
          {copy.why.map((line) => (
            <li key={line} className="flex items-start gap-2.5 text-[1.0625rem]">
              <span className="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-[var(--brand)] text-[var(--brand-contrast)]" aria-hidden><Check className="size-3.5" /></span>
              {line}
            </li>
          ))}
        </ul>
      </section>
    </MarketingPage>
  )
}
