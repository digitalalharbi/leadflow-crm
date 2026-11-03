import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Globe, Megaphone, Repeat2, Timer } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { applyLocale } from '@/i18n'
import { Logo } from '@/components/brand'
import { Journey } from '@/components/public/Journey'
import markUrl from '@/assets/brand/logo/mono-black/mohtam-mark-mono-black.svg?url'
import { cn } from '@/lib/cn'
import type { Branding } from '@/types/api'

export interface AuthProvider {
  key: string
  label: string
  url: string
}

const PILLARS: Array<{ key: 'ads' | 'speed' | 'loop'; icon: LucideIcon }> = [
  { key: 'ads', icon: Megaphone },
  { key: 'speed', icon: Timer },
  { key: 'loop', icon: Repeat2 },
]

const JOURNEY = ['arrived', 'contacted', 'qualified', 'appointment', 'won'] as const

/**
 * Every door into an account stands in this one frame: the brand panel at the start edge, and the form's side. The login
 * screen renders the *customer's* brand when it has one: logo, colours and product name come from a public endpoint.
 */
export function AuthShell({ children, testId, branding, panel = true, footer = true, intro = false }: {
  children: ReactNode
  testId?: string
  branding?: Branding | null
  /** Off for a console that is not a company's door. */
  panel?: boolean
  footer?: boolean
  /** The headline and the journey around the card, on a phone, where there is no panel. */
  intro?: boolean
}) {
  const { t, i18n } = useTranslation()
  const systemName = branding?.system_name ?? t('common.appName')
  const logo = branding?.login_logo_url ?? branding?.logo_url
  /*
   * One name for the question the whole brand panel turns on: is the picture the tenant's or ours? A
   * photograph they uploaded keeps the dark, white-type treatment; a workspace with its own name and logo
   * gets its own heading; everyone else gets مهتم — the logo, the headline, the journey.
   */
  const ownBackground = Boolean(branding?.login_background_url)
  const platformLook = !ownBackground && !branding?.company_name && !logo && !branding?.system_name
  const journey = JOURNEY.map((key) => t(`brand.journey.${key}`))

  return (
    <div className={cn('grid min-h-dvh', panel && 'lg:grid-cols-2')} data-testid={testId}>
      {/* The brand panel: first in the markup, so it sits at the start edge — the right, in Arabic. */}
      {panel && (
        <div
          className={cn('relative hidden overflow-hidden lg:block', ownBackground ? undefined : 'login-panel')}
          style={ownBackground ? { background: `url(${branding?.login_background_url}) center/cover` } : undefined}
          data-testid="login-brand-panel"
        >
          {/* The official mark, large and quiet, in the second pine: cut from the kit's own file and filled with a token. */}
          {!ownBackground && (
            <span
              aria-hidden
              data-testid="login-watermark"
              className="pointer-events-none absolute -top-24 -end-28 size-[36rem] bg-[var(--chrome-2)]"
              style={{ WebkitMask: `url("${markUrl}") center / contain no-repeat`, mask: `url("${markUrl}") center / contain no-repeat` }}
            />
          )}
          {ownBackground && <div className="absolute inset-0 bg-[var(--color-neutral-950)]/15" />}

          <div className="relative flex h-full flex-col justify-between p-12 text-[var(--chrome-ink)]">
            <div>{platformLook && <Logo variant="reverse" layout="horizontal" height={56} />}</div>

            <div>
              {platformLook ? (
                <>
                  <p className="inline-flex rounded-full bg-[var(--chrome-2)] px-3.5 py-1 text-[0.9375rem] font-semibold text-[var(--accent-on-dark)]" data-testid="login-badge">{t('brand.badge')}</p>
                  <h2 className="mt-4 text-[2.625rem] leading-[1.2] font-bold" data-testid="login-headline">{t('brand.tagline')}</h2>
                  <p className="mt-3 max-w-md text-[1.0625rem] leading-relaxed text-[var(--chrome-ink)]/80" data-testid="login-description">{t('brand.subtext')}</p>
                  <Journey steps={journey} tone="dark" className="mt-7" testId="login-journey" />
                </>
              ) : (
                <>
                  <h2 className="text-[2.125rem] leading-[1.15] font-semibold ltr:tracking-[-0.02em]">{branding?.company_name ?? systemName}</h2>
                  {/* The tagline belongs to مهتم; a workspace's own name stands alone. */}
                  <p className="mt-2.5 text-[1.0625rem] font-medium text-[var(--chrome-ink)]/90">{t('auth.welcomeSubtitle')}</p>
                </>
              )}

              {!ownBackground && (
                <ul className="mt-8 space-y-2.5" data-testid="login-pillars">
                  {PILLARS.map(({ key, icon: Icon }) => (
                    <li key={key} className="flex items-center gap-3 text-[1rem] font-medium">
                      <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[var(--chrome-2)] text-[var(--chrome-muted)]" aria-hidden><Icon className="size-4" /></span>
                      {t(`brand.pillars.${key}`)}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </div>
      )}

      {/* The form side: limestone, and nothing else competing with the form. */}
      <div className="flex min-w-0 flex-col bg-[var(--color-canvas)] px-5 py-4 pt-[max(1rem,env(safe-area-inset-top))] sm:px-10 lg:py-5">
        <div className="flex items-center justify-between gap-3">
          {/* The logo leads on a phone, where there is no brand panel; on a laptop the panel carries it. */}
          <div className={cn(panel && platformLook && 'lg:invisible')}>
            {logo || branding?.system_name ? (
              <div className="flex items-center gap-2.5">
                {logo ? <img src={logo} alt="" className="size-9 rounded-[var(--radius-control)] object-contain" /> : <Logo variant="auto" layout="mark" height={32} />}
                <span className="text-[1.0625rem] font-semibold">{systemName}</span>
              </div>
            ) : (
              <Logo variant="auto" layout="horizontal" height={48} />
            )}
          </div>

          <button
            type="button"
            onClick={() => applyLocale(i18n.language.startsWith('ar') ? 'en' : 'ar')}
            className="pressable flex min-h-11 items-center gap-1.5 rounded-full px-3 text-[0.9375rem] text-[var(--color-ink-muted)] hover:bg-[var(--color-chrome-fill)] hover:text-[var(--color-ink)]"
          >
            <Globe className="size-4" aria-hidden />
            {i18n.language.startsWith('ar') ? 'English' : 'العربية'}
          </button>
        </div>

        <div className="flex flex-1 flex-col items-center justify-center gap-3 py-3">
          {/* On a phone the idea is one line above the card, not a panel beside it. */}
          {intro && platformLook && (
            <div className="w-full max-w-[27rem] lg:hidden" data-testid="login-mobile-intro">
              <p className="text-[1.5rem] font-bold leading-tight">{t('brand.tagline')}</p>
              <p className="mt-1 text-[0.9375rem] text-[var(--color-ink-muted)]">{t('brand.badge')}</p>
            </div>
          )}

          {children}

          {/* The journey, short, for the person on a phone. */}
          {intro && platformLook && (
            <div className="w-full max-w-[27rem] rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] px-4 py-3 lg:hidden" data-testid="login-mobile-journey">
              <Journey steps={journey} tone="light" testId="login-mobile-journey-steps" compact />
            </div>
          )}

          {footer && (
            <nav aria-label="مهتم" className="flex flex-wrap justify-center gap-x-1 text-[0.9375rem]">
              <Link to="/" className="inline-flex min-h-11 items-center rounded-lg px-2.5 font-medium text-[var(--brand-text)] hover:underline" data-testid="login-about">{t('auth.about')}</Link>
              <Link to="/privacy" className="inline-flex min-h-11 items-center rounded-lg px-2.5 text-[var(--color-ink-muted)] hover:underline">{t('brand.links.privacy')}</Link>
              <Link to="/terms" className="inline-flex min-h-11 items-center rounded-lg px-2.5 text-[var(--color-ink-muted)] hover:underline">{t('brand.links.terms')}</Link>
            </nav>
          )}
        </div>
      </div>
    </div>
  )
}

/**
 * The heading above, the card beneath it, one quiet line of help below. Another way in appears only when it really works.
 */
export function AuthCard({ title, subtitle, children, providers = [], help = false, testId }: {
  title: string
  subtitle?: string
  children: ReactNode
  providers?: AuthProvider[]
  help?: boolean
  testId?: string
}) {
  const { t } = useTranslation()

  return (
    <section className="w-full max-w-[27rem]" data-testid={testId}>
      <h1 className="text-[1.75rem] leading-tight font-bold" data-testid="page-title">{title}</h1>
      {subtitle && <p className="mt-1 text-[0.9375rem] text-[var(--color-ink-muted)]">{subtitle}</p>}

      <div className="mt-4 space-y-3 rounded-[1.25rem] border border-[var(--color-line)] bg-[var(--color-surface)] p-5 shadow-[0_1px_2px_rgb(20_32_29/0.04)] sm:p-6">
        {children}
        {providers.length > 0 && (
          <div className="space-y-3" data-testid="login-providers">
            <div className="flex items-center gap-3 text-[0.875rem] text-[var(--color-ink-subtle)]">
              <span className="h-px flex-1 bg-[var(--color-line)]" />
              {t('auth.orAnotherWay')}
              <span className="h-px flex-1 bg-[var(--color-line)]" />
            </div>
            {providers.map((provider) => (
              <a key={provider.key} href={provider.url} className="pressable flex min-h-11 items-center justify-center rounded-[var(--radius-control)] border border-[var(--color-border)] text-[0.9375rem] font-medium">
                {t('auth.continueWith', { provider: provider.label })}
              </a>
            ))}
          </div>
        )}
      </div>

      {help && (
        <p className="mt-2 flex min-h-11 items-center justify-center gap-1 text-[0.9375rem] text-[var(--color-ink-muted)]">
          {t('auth.needHelp')}
          <Link to="/contact" className="font-medium text-[var(--brand-text)] hover:underline" data-testid="login-contact">{t('auth.contactUs')}</Link>
        </p>
      )}
    </section>
  )
}
